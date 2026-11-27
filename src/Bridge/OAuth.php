<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\Admin\Users;
use Lou\Db\Database;
use Lou\LouException;

/**
 * Lou as its own OAuth 2.1 authorization server for the MCP bridge, so assistants connect by signing in
 * instead of carrying a lou_ token in their URL.
 *
 * - Clients register themselves (RFC 7591 dynamic registration: Claude, ChatGPT, Copilot Studio "dynamic
 *   discovery", VS Code…) or an admin creates one with a client id and secret (Copilot Studio "manual").
 * - Authorization code flow with PKCE (S256). PKCE is required unless the client authenticates with its secret,
 *   because Copilot Studio's manual mode sends none.
 * - A person who can publish (editor or admin) approves the connection on Lou's own login. The approval is a
 *   "grant": one per app and person, listed and revocable under Users → AI assistants. The assistant then works
 *   like a token assistant ("llm:<name>"): its own draft, submit for review, never publish.
 * - Access tokens live an hour, refresh tokens 90 days and are rotated on every use; a refresh token used twice
 *   (a copy in someone else's hands) ends the grant. Only SHA-256 hashes are stored, in the SQLite database.
 * - A grant stops working when its person is removed, drops below editor, changes password or signs out everywhere.
 */
final class OAuth
{
    public const SCOPE = 'site';
    public const CODE_TTL = 300;
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 90 * 86400;
    /** A rotated refresh token sent again within this many seconds is a retry (lost response), not theft. */
    public const REUSE_GRACE = 60;
    public const MAX_DYNAMIC_CLIENTS = 500;
    /** Unused self-registered clients are forgotten after this long. */
    public const UNUSED_CLIENT_TTL = 7 * 86400;
    /** Who may approve a connection: the same people who can publish what an assistant submits. */
    public const APPROVER_ROLE = 'editor';

    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]', '::1'];
    private const BAD_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'filesystem', 'ftp', 'ws', 'wss'];

    public function __construct(
        private readonly Database $db,
        private readonly Users $users,
        private readonly ?int $now = null,
    ) {
    }

    private function time(): int
    {
        return $this->now ?? time();
    }

    // ---------------------------------------------------------------- clients

    /**
     * Register a client. $dynamic: self-registered over the open endpoint (RFC 7591). Returns the stored client
     * and, for confidential clients, the secret (shown once).
     * @param array<string, mixed> $meta client_name, redirect_uris, token_endpoint_auth_method
     * @return array{client: array<string, mixed>, secret: ?string}
     */
    public function register(array $meta, bool $dynamic, ?string $by = null): array
    {
        $name = self::cleanName($meta['client_name'] ?? '');
        if ($name === '') {
            if (!$dynamic) {
                throw new LouException('Give the app a name, e.g. Copilot Studio – Kner agent');
            }
            $name = 'Unnamed app';
        }
        $uris = $meta['redirect_uris'] ?? [];
        if (!is_array($uris) || !array_is_list($uris) || count($uris) > 5) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must be a list of at most 5 addresses');
        }
        foreach ($uris as $u) {
            if (!is_string($u) || !self::validRedirect($u)) {
                throw new OAuthError('invalid_redirect_uri', 'Not an allowed redirect address: ' . (is_string($u) ? mb_substr($u, 0, 200) : gettype($u))
                    . ' (https, http only on localhost, or an app scheme)');
            }
        }
        if ($dynamic && $uris === []) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris is required');
        }
        $method = $meta['token_endpoint_auth_method'] ?? ($dynamic ? 'client_secret_basic' : 'client_secret_post');
        if (!in_array($method, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method must be none, client_secret_basic or client_secret_post');
        }
        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $k => $ok) {
            if (isset($meta[$k]) && (!is_array($meta[$k]) || array_diff($meta[$k], $ok))) {
                throw new OAuthError('invalid_client_metadata', "$k may only contain " . implode(', ', $ok));
            }
        }
        if ($dynamic) {
            $this->forgetUnusedClients();
            $count = (int) $this->db->run('SELECT COUNT(*) FROM oauth_clients WHERE dynamic = 1')->fetchColumn();
            if ($count >= self::MAX_DYNAMIC_CLIENTS) {
                throw new OAuthError('temporarily_unavailable', 'Too many registered apps; try again later', 503);
            }
        }
        $id = 'louc_' . bin2hex(random_bytes(12));
        $secret = $method === 'none' ? null : 'lous_' . bin2hex(random_bytes(32));
        $this->db->run('INSERT INTO oauth_clients (id, secret_hash, name, redirect_uris, auth_method, dynamic, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $secret === null ? null : hash('sha256', $secret), $name, json_encode(array_values($uris), JSON_UNESCAPED_SLASHES),
            $method, $dynamic ? 1 : 0, $by, gmdate('c', $this->time()),
        ]);
        return ['client' => (array) $this->client($id), 'secret' => $secret];
    }

    /** @return array<string, mixed>|null with redirect_uris decoded */
    public function client(string $id): ?array
    {
        if (!preg_match('/^louc_[a-f0-9]{24}$/', $id)) {
            return null;
        }
        $row = $this->db->run('SELECT * FROM oauth_clients WHERE id = ?', [$id])->fetch();
        if (!$row) {
            return null;
        }
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        return $row;
    }

    /** Clients an admin created (with a secret), newest first. @return list<array<string, mixed>> */
    public function manualClients(): array
    {
        $rows = $this->db->run('SELECT * FROM oauth_clients WHERE dynamic = 0 ORDER BY created_at DESC')->fetchAll();
        return array_map(static fn (array $r): array => ['redirect_uris' => json_decode((string) $r['redirect_uris'], true) ?: []] + $r, $rows);
    }

    public function setRedirect(string $clientId, string $uri): void
    {
        $uri = trim($uri);
        if ($uri !== '' && !self::validRedirect($uri)) {
            throw new LouException('That is not a usable return address. Paste the redirect URL the app shows (it starts with https://).');
        }
        $this->db->run('UPDATE oauth_clients SET redirect_uris = ? WHERE id = ? AND dynamic = 0', [json_encode($uri === '' ? [] : [$uri], JSON_UNESCAPED_SLASHES), $clientId]);
    }

    /** Delete a client and end every connection made with it. */
    public function deleteClient(string $clientId): void
    {
        foreach ($this->db->run('SELECT id FROM oauth_grants WHERE client_id = ?', [$clientId])->fetchAll() as $g) {
            $this->revokeGrant((string) $g['id']);
        }
        $this->db->run('DELETE FROM oauth_codes WHERE client_id = ?', [$clientId]);
        $this->db->run('DELETE FROM oauth_clients WHERE id = ?', [$clientId]);
    }

    /** https anywhere, http only on this computer (RFC 8252 loopback), or an app's own scheme (cursor://…). */
    public static function validRedirect(string $uri): bool
    {
        if (strlen($uri) > 500 || preg_match('/[\s\x00-\x1f\x7f]/', $uri) || str_contains($uri, '#')) {
            return false;
        }
        $p = parse_url($uri);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if ($p === false || $scheme === '' || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if ($scheme === 'https') {
            return ($p['host'] ?? '') !== '';
        }
        if ($scheme === 'http') {
            return in_array(strtolower((string) ($p['host'] ?? '')), self::LOOPBACK, true);
        }
        return (bool) preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) && !in_array($scheme, self::BAD_SCHEMES, true);
    }

    /** Exact match, except that loopback addresses may use any port (native apps pick a free one). */
    public static function redirectMatches(array $registered, string $uri): bool
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        $p = parse_url($uri);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'http' || !in_array(strtolower((string) ($p['host'] ?? '')), self::LOOPBACK, true)) {
            return false;
        }
        $strip = static fn (string $u): string => (string) preg_replace('#^(http://[^/:?]+|http://\[[^\]]+\])(:\d+)?#i', '$1', $u);
        foreach ($registered as $r) {
            if ($strip((string) $r) === $strip($uri)) {
                return true;
            }
        }
        return false;
    }

    private function forgetUnusedClients(): void
    {
        $this->db->run('DELETE FROM oauth_clients WHERE dynamic = 1 AND created_at < ? AND id NOT IN (SELECT client_id FROM oauth_grants)',
            [gmdate('c', $this->time() - self::UNUSED_CLIENT_TTL)]);
    }

    // ---------------------------------------------------------------- authorization

    /**
     * Check an authorization request (the query of the authorize URL, or the consent form that repeats it).
     * Returns what the consent screen shows and the approval needs.
     * @param array<string, mixed> $q
     * @return array{client: array<string, mixed>, redirect_uri: string, state: string, challenge: string, resource: string, scope: string, first_use: bool}
     */
    public function checkRequest(array $q, string $resourceUrl): array
    {
        $s = static fn (string $k): string => is_string($q[$k] ?? null) ? $q[$k] : '';
        $client = $this->client($s('client_id'));
        if ($client === null) {
            throw new OAuthError('invalid_client', 'This app is not registered with the site (unknown client_id). Remove the connection in the app and add it again.');
        }
        $uri = $s('redirect_uri');
        $registered = $client['redirect_uris'];
        $firstUse = false;
        if ($uri === '' && count($registered) === 1) {
            $uri = (string) $registered[0];
        }
        if ($registered === [] && !$client['dynamic'] && str_starts_with($uri, 'https://') && self::validRedirect($uri)) {
            $firstUse = true;   // an admin-made client without a return address yet: the first approval records it
        } elseif ($uri === '' || !self::redirectMatches($registered, $uri)) {
            throw new OAuthError('invalid_request', 'The return address (redirect_uri) is not registered for this app.');
        }
        $state = mb_substr($s('state'), 0, 1000);
        $fail = static fn (string $error, string $desc): OAuthError => new OAuthError($error, $desc, 400, $uri, $state);
        if ($s('response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Only response_type=code is supported');
        }
        $challenge = $s('code_challenge');
        if ($challenge !== '' || $client['secret_hash'] === null) {
            if ($s('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
                throw $fail('invalid_request', 'PKCE is required: send code_challenge with code_challenge_method=S256');
            }
        }
        $resource = $s('resource');
        if ($resource !== '' && strtolower((string) parse_url($resource, PHP_URL_HOST)) !== strtolower((string) parse_url($resourceUrl, PHP_URL_HOST))) {
            throw $fail('invalid_target', 'This server only issues tokens for ' . $resourceUrl);
        }
        return ['client' => $client, 'redirect_uri' => $uri, 'state' => $state, 'challenge' => $challenge,
            'resource' => $resource !== '' ? $resource : $resourceUrl, 'scope' => self::SCOPE, 'first_use' => $firstUse];
    }

    /** The person approved: returns the one-time code for the redirect. */
    public function approve(array $request, string $user): string
    {
        $role = $this->users->role($user);
        if ($role === null || !Users::atLeast($role, self::APPROVER_ROLE)) {
            throw new LouException('Only editors and administrators can connect an assistant.');
        }
        if ($request['first_use']) {
            $this->setRedirect((string) $request['client']['id'], $request['redirect_uri']);
        }
        $code = bin2hex(random_bytes(32));
        $this->db->run('DELETE FROM oauth_codes WHERE expires < ?', [$this->time()]);
        $this->db->run('INSERT INTO oauth_codes (hash, client_id, user, redirect_uri, challenge, resource, expires) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            hash('sha256', $code), $request['client']['id'], strtolower($user), $request['redirect_uri'], $request['challenge'],
            $request['resource'], $this->time() + self::CODE_TTL,
        ]);
        return $code;
    }

    /** The URL to send the browser back to, with the code (or an error) and state. */
    public static function redirectUrl(string $uri, array $params, string $issuer): string
    {
        $params = array_filter($params + ['iss' => $issuer], static fn ($v): bool => $v !== '' && $v !== null);
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    // ---------------------------------------------------------------- token endpoint

    /**
     * POST token: authorization_code or refresh_token. $clientId/$secret come from HTTP Basic or the form.
     * @param array<string, string> $form
     * @return array<string, mixed> the token response
     */
    public function token(array $form, string $clientId, ?string $secret): array
    {
        $client = $this->authenticate($clientId, $secret);
        $this->db->run('DELETE FROM oauth_tokens WHERE expires < ?', [$this->time() - self::REUSE_GRACE]);
        return match ($form['grant_type'] ?? '') {
            'authorization_code' => $this->exchangeCode($client, $form, $secret !== null),
            'refresh_token' => $this->refresh($client, (string) ($form['refresh_token'] ?? '')),
            default => throw new OAuthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token'),
        };
    }

    /** Admin-made clients must send their secret. Self-registered ones may (then it must be right) or rely on PKCE. */
    public function authenticate(string $clientId, ?string $secret): array
    {
        $client = $this->client($clientId);
        if ($client === null) {
            throw new OAuthError('invalid_client', 'Unknown client', 401);
        }
        if ($secret !== null && $secret !== '') {
            if ($client['secret_hash'] === null || !hash_equals((string) $client['secret_hash'], hash('sha256', $secret))) {
                throw new OAuthError('invalid_client', 'Wrong client secret', 401);
            }
        } elseif (!$client['dynamic'] && $client['secret_hash'] !== null) {
            throw new OAuthError('invalid_client', 'This client must send its client secret', 401);
        }
        return $client;
    }

    private function exchangeCode(array $client, array $form, bool $authenticated): array
    {
        $hash = hash('sha256', (string) ($form['code'] ?? ''));
        $row = $this->db->run('SELECT * FROM oauth_codes WHERE hash = ?', [$hash])->fetch();
        $this->db->run('DELETE FROM oauth_codes WHERE hash = ?', [$hash]);   // one use, whatever happens next
        if (!$row || (int) $row['expires'] < $this->time() || $row['client_id'] !== $client['id']) {
            throw new OAuthError('invalid_grant', 'The code is unknown, used or expired. Start the sign-in again.');
        }
        if (isset($form['redirect_uri']) && $form['redirect_uri'] !== '' && $form['redirect_uri'] !== $row['redirect_uri']) {
            throw new OAuthError('invalid_grant', 'redirect_uri does not match the authorization request');
        }
        if ((string) $row['challenge'] !== '') {
            $verifier = (string) ($form['code_verifier'] ?? '');
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals((string) $row['challenge'], $expected)) {
                throw new OAuthError('invalid_grant', 'code_verifier does not match the code_challenge');
            }
        } elseif (!$authenticated) {
            throw new OAuthError('invalid_grant', 'PKCE or the client secret is required');
        }
        $user = (string) $row['user'];
        $role = $this->users->role($user);
        if ($role === null || !Users::atLeast($role, self::APPROVER_ROLE)) {
            throw new OAuthError('invalid_grant', 'The person who approved this can no longer connect assistants');
        }
        return $this->issue($this->grantFor($client, $user));
    }

    private function refresh(array $client, string $token): array
    {
        $row = $this->db->run('SELECT t.*, g.client_id FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id WHERE t.hash = ? AND t.kind = ?',
            [hash('sha256', $token), 'refresh'])->fetch();
        if (!$row || $row['client_id'] !== $client['id'] || (int) $row['expires'] < $this->time()) {
            throw new OAuthError('invalid_grant', 'The refresh token is unknown or expired. Sign in again.');
        }
        if ($row['used_at'] !== null) {
            if ($this->time() - (int) $row['used_at'] > self::REUSE_GRACE) {
                // an old refresh token came back: someone else may have a copy, so end the connection
                $this->revokeGrant((string) $row['grant_id']);
                $this->db->audit('llm', 'oauth-refresh-reuse', (string) $row['grant_id']);
            }
            throw new OAuthError('invalid_grant', 'The refresh token was already used. Sign in again.');
        }
        $grant = $this->activeGrant((string) $row['grant_id']);
        if ($grant === null) {
            throw new OAuthError('invalid_grant', 'This connection was ended. Sign in again.');
        }
        $this->db->run('UPDATE oauth_tokens SET used_at = ? WHERE hash = ?', [$this->time(), $row['hash']]);
        return $this->issue($grant);
    }

    /** One grant per app and person: approving again keeps the same name, and so the same draft. */
    private function grantFor(array $client, string $user): array
    {
        $sv = $this->users->sessionVersion($user);
        $row = $this->db->run('SELECT * FROM oauth_grants WHERE client_id = ? AND user = ?', [$client['id'], $user])->fetch();
        if ($row) {
            $this->db->run('UPDATE oauth_grants SET sv = ? WHERE id = ?', [$sv, $row['id']]);
            return ['sv' => $sv] + $row;
        }
        $id = bin2hex(random_bytes(8));
        $name = mb_substr((string) $client['name'], 0, 40) . ' – ' . $user;
        $this->db->run('INSERT INTO oauth_grants (id, client_id, user, name, sv, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $client['id'], $user, $name, $sv, gmdate('c', $this->time())]);
        $this->db->audit('admin', 'oauth-connect', $name, $user);
        return (array) $this->db->run('SELECT * FROM oauth_grants WHERE id = ?', [$id])->fetch();
    }

    private function issue(array $grant): array
    {
        $access = 'louat_' . bin2hex(random_bytes(32));
        $refresh = 'lourt_' . bin2hex(random_bytes(32));
        $t = $this->time();
        $this->db->run('INSERT INTO oauth_tokens (hash, grant_id, kind, expires) VALUES (?, ?, ?, ?), (?, ?, ?, ?)', [
            hash('sha256', $access), $grant['id'], 'access', $t + self::ACCESS_TTL,
            hash('sha256', $refresh), $grant['id'], 'refresh', $t + self::REFRESH_TTL,
        ]);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => self::SCOPE];
    }

    // ---------------------------------------------------------------- the MCP side

    /** The bridge caller name ("Claude – bence") for a valid access token, or null. */
    public function verify(string $token): ?string
    {
        if (!preg_match('/^louat_[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->db->run('SELECT grant_id, expires FROM oauth_tokens WHERE hash = ? AND kind = ?', [hash('sha256', $token), 'access'])->fetch();
        if (!$row || (int) $row['expires'] < $this->time()) {
            return null;
        }
        $grant = $this->activeGrant((string) $row['grant_id']);
        if ($grant === null) {
            return null;
        }
        if ((string) ($grant['last_used'] ?? '') < gmdate('c', $this->time() - 300)) {
            $this->db->run('UPDATE oauth_grants SET last_used = ? WHERE id = ?', [gmdate('c', $this->time()), $grant['id']]);
        }
        return (string) $grant['name'];
    }

    /** A grant whose person still exists, can still approve, and hasn't changed password or signed out everywhere since. */
    private function activeGrant(string $id): ?array
    {
        $g = $this->db->run('SELECT * FROM oauth_grants WHERE id = ?', [$id])->fetch();
        if (!$g) {
            return null;
        }
        $role = $this->users->role((string) $g['user']);
        if ($role === null || !Users::atLeast($role, self::APPROVER_ROLE) || (int) $g['sv'] !== $this->users->sessionVersion((string) $g['user'])) {
            return null;
        }
        return $g;
    }

    /** Connections for the AI assistants tab, newest first, with whether each still works. @return list<array<string, mixed>> */
    public function grants(): array
    {
        $rows = $this->db->run('SELECT g.*, c.dynamic FROM oauth_grants g LEFT JOIN oauth_clients c ON c.id = g.client_id ORDER BY g.created_at DESC')->fetchAll();
        foreach ($rows as &$r) {
            $r['active'] = $this->activeGrant((string) $r['id']) !== null;
        }
        return $rows;
    }

    public function revokeGrant(string $id): void
    {
        $this->db->run('DELETE FROM oauth_tokens WHERE grant_id = ?', [$id]);
        $this->db->run('DELETE FROM oauth_grants WHERE id = ?', [$id]);
    }

    /** RFC 7009: revoking either token of a connection ends the whole connection. Unknown tokens are not an error. */
    public function revokeToken(string $token, array $client): void
    {
        $row = $this->db->run('SELECT t.grant_id, g.client_id FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id WHERE t.hash = ?', [hash('sha256', $token)])->fetch();
        if ($row && $row['client_id'] === $client['id']) {
            $this->revokeGrant((string) $row['grant_id']);
        }
    }

    /** A display name from untrusted client metadata: no tags or control characters, one line, at most 60 characters. */
    public static function cleanName(mixed $name): string
    {
        if (!is_string($name)) {
            return '';
        }
        $name = (string) preg_replace('/[\x00-\x1f\x7f\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', strip_tags($name));
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 60);
    }
}
