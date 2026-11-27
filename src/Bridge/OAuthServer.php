<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\Admin\Users;
use Lou\App;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\Http\Response;
use Lou\LouException;

/**
 * /<endpoint_dir>/oauth.php: the machine side of Lou's OAuth server (see OAuth). The person-facing consent
 * screen is in the admin (admin.php?r=authorize), so it uses the admin's login, two-step login included.
 *
 *   GET  oauth.php?well-known=oauth-authorization-server   RFC 8414 metadata (also served for openid-configuration)
 *   GET  oauth.php?well-known=oauth-protected-resource     RFC 9728 metadata of the MCP endpoint
 *   GET  oauth.php?authorize&…                             forwards to the admin's consent screen
 *   POST oauth.php?register                                RFC 7591 dynamic client registration
 *   POST oauth.php?token                                   authorization_code and refresh_token grants
 *   POST oauth.php?revoke                                  RFC 7009
 *
 * The web root's .htaccess rewrites /.well-known/oauth-authorization-server (and friends) here, so clients
 * that only look at the standard addresses find it. Everything answers CORS requests from any origin:
 * nothing here uses cookies, and browser-based MCP clients (the MCP Inspector) need it.
 */
final class OAuthServer
{
    private const REGISTRATIONS_PER_HOUR = 20;
    private const TOKEN_FAILURES_PER_15_MIN = 30;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly ?int $now = null,
    ) {
    }

    public static function run(string $root): void
    {
        try {
            $app = new App($root);
            (new self($app, new Database($app->databaseFile())))->handle(Request::fromGlobals())->send();
        } catch (\Throwable $e) {
            @file_put_contents($root . '/var/error.log', gmdate('c') . ' oauth.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
            Response::json(500, ['error' => 'server_error', 'error_description' => 'Server error, details are in var/error.log'])->send();
        }
    }

    public function oauth(): OAuth
    {
        return new OAuth($this->db, new Users($this->app->varDir . '/users.json'), $this->now);
    }

    /** Issuer and endpoint addresses, all derived from the site address. @return array<string, string> */
    public static function urls(App $app, ?Request $req = null): array
    {
        $site = $app->siteUrl($req);
        $oauth = $site . $app->endpoint('oauth.php');
        return [
            'issuer'    => $site,
            'authorize' => $oauth . '?authorize',
            'token'     => $oauth . '?token',
            'register'  => $oauth . '?register',
            'revoke'    => $oauth . '?revoke',
            'resource_metadata' => $oauth . '?well-known=oauth-protected-resource',
            'mcp'       => $site . $app->endpoint('mcp.php'),
        ];
    }

    public function handle(Request $req): Response
    {
        $q = self::normalizeQuery($req->query);
        if ($req->method === 'OPTIONS') {
            return $this->cors(new Response(204));
        }
        $urls = self::urls($this->app, $req);
        if ($req->method === 'GET' && isset($q['well-known'])) {
            return $this->cors(match ((string) $q['well-known']) {
                'oauth-protected-resource' => Response::json(200, $this->resourceMetadata($urls)),
                'oauth-authorization-server', 'openid-configuration' => Response::json(200, $this->serverMetadata($urls)),
                default => Response::json(404, ['error' => 'not_found']),
            });
        }
        if ($req->method === 'GET' && array_key_exists('authorize', $q)) {
            unset($q['authorize'], $q['r']);
            return new Response(302, '', ['Location' => $this->app->endpoint('admin.php') . '?' . http_build_query(['r' => 'authorize'] + $q, '', '&', PHP_QUERY_RFC3986)]);
        }
        if ($req->method !== 'POST') {
            return $this->cors(Response::json(405, ['error' => 'invalid_request', 'error_description' => 'Use POST']));
        }
        try {
            $form = $this->form($req);
            if (array_key_exists('register', $q)) {
                return $this->cors($this->register($req));
            }
            if (array_key_exists('token', $q)) {
                return $this->cors($this->token($req, $form));
            }
            if (array_key_exists('revoke', $q)) {
                [$id, $secret] = $this->credentials($req, $form);
                $this->oauth()->revokeToken((string) ($form['token'] ?? ''), $this->oauth()->authenticate($id, $secret));
                return $this->cors(new Response(200, ''));
            }
            return $this->cors(Response::json(404, ['error' => 'not_found']));
        } catch (OAuthError $e) {
            $headers = $e->status === 401 ? ['WWW-Authenticate' => 'Basic realm="oauth"'] : [];
            return $this->cors(new Response($e->status, (string) json_encode(['error' => $e->error, 'error_description' => $e->getMessage()], JSON_UNESCAPED_SLASHES),
                ['Content-Type' => 'application/json; charset=utf-8', 'Pragma' => 'no-cache'] + $headers));
        }
    }

    /** @return array<string, mixed> */
    public function serverMetadata(array $urls): array
    {
        return [
            'issuer' => $urls['issuer'],
            'authorization_endpoint' => $urls['authorize'],
            'token_endpoint' => $urls['token'],
            'registration_endpoint' => $urls['register'],
            'revocation_endpoint' => $urls['revoke'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'scopes_supported' => [OAuth::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function resourceMetadata(array $urls): array
    {
        return [
            'resource' => $urls['mcp'],
            'authorization_servers' => [$urls['issuer']],
            'scopes_supported' => [OAuth::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => (string) $this->app->config['name'],
        ];
    }

    private function register(Request $req): Response
    {
        $key = 'oauth-register|' . substr(hash('sha256', $req->ip), 0, 32);
        if ($this->count($key, 3600) >= self::REGISTRATIONS_PER_HOUR) {
            throw new OAuthError('temporarily_unavailable', 'Too many registrations from this address, try again later', 429);
        }
        $this->hit($key);
        $meta = json_decode($req->body, true);
        if (!is_array($meta) || array_is_list($meta)) {
            throw new OAuthError('invalid_client_metadata', 'Send the client metadata as a JSON object');
        }
        try {
            ['client' => $c, 'secret' => $secret] = $this->oauth()->register($meta, true);
        } catch (LouException $e) {
            throw new OAuthError('invalid_client_metadata', $e->getMessage());
        }
        return Response::json(201, [
            'client_id' => $c['id'],
            'client_id_issued_at' => strtotime((string) $c['created_at']),
            'client_name' => $c['name'],
            'redirect_uris' => $c['redirect_uris'],
            'token_endpoint_auth_method' => $c['auth_method'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => OAuth::SCOPE,
        ] + ($secret !== null ? ['client_secret' => $secret, 'client_secret_expires_at' => 0] : []));
    }

    private function token(Request $req, array $form): Response
    {
        $key = 'oauth-token|' . substr(hash('sha256', $req->ip), 0, 32);
        if ($this->count($key, 900) >= self::TOKEN_FAILURES_PER_15_MIN) {
            throw new OAuthError('temporarily_unavailable', 'Too many failed attempts, try again later', 429);
        }
        [$id, $secret] = $this->credentials($req, $form);
        try {
            $out = $this->oauth()->token($form, $id, $secret);
        } catch (OAuthError $e) {
            $this->hit($key);
            throw $e;
        }
        return new Response(200, (string) json_encode($out, JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json; charset=utf-8', 'Pragma' => 'no-cache']);
    }

    /** client_id and secret from HTTP Basic (RFC 6749 2.3.1, form-encoded inside) or the form. @return array{0: string, 1: ?string} */
    private function credentials(Request $req, array $form): array
    {
        if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $req->header('authorization'), $m)) {
            $pair = explode(':', (string) base64_decode($m[1], true), 2);
            if (count($pair) === 2) {
                return [urldecode($pair[0]), urldecode($pair[1])];
            }
        }
        $secret = $form['client_secret'] ?? null;
        return [(string) ($form['client_id'] ?? ''), is_string($secret) && $secret !== '' ? $secret : null];
    }

    /** The token and revoke endpoints take form posts; a few clients send JSON instead, which is accepted too. @return array<string, string> */
    private function form(Request $req): array
    {
        $data = $req->post ?: (json_decode($req->body, true) ?: []);
        return array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', is_array($data) ? $data : []);
    }

    /**
     * Some clients add their parameters with another "?" to an endpoint that already has one
     * (oauth.php?authorize?client_id=…). Read those as if the second "?" were "&".
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function normalizeQuery(array $query): array
    {
        $out = [];
        foreach ($query as $k => $v) {
            $k = (string) $k;
            if (str_contains($k, '?')) {
                [$first, $rest] = explode('?', $k, 2);
                $out[$first] = '';
                $out[$rest] = $v;
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function cors(Response $r): Response
    {
        return new Response($r->status, $r->body, $r->headers + [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    private function time(): int
    {
        return $this->now ?? time();
    }

    private function count(string $key, int $window): int
    {
        return (int) $this->db->run('SELECT COUNT(*) FROM rate_limits WHERE key = ? AND ts > ?', [$key, $this->time() - $window])->fetchColumn();
    }

    private function hit(string $key): void
    {
        $this->db->run('INSERT INTO rate_limits (key, ts) VALUES (?, ?)', [$key, $this->time()]);
    }
}
