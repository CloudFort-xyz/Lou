<?php

declare(strict_types=1);

use Lou\Admin\AdminApp;
use Lou\Admin\ArraySession;
use Lou\Admin\Users;
use Lou\App;
use Lou\Bridge\McpServer;
use Lou\Bridge\OAuth;
use Lou\Bridge\OAuthServer;
use Lou\Content\Workspace;
use Lou\Db\Database;
use Lou\Http\Request;

/** A built site with an admin (bence), an editor (kliens) and a contributor (anna). */
function oauth_setup(): array
{
    $root = install();
    $app = new App($root);
    (new Lou\Build\Builder($app))->build();
    $users = new Users($app->varDir . '/users.json');
    $users->add('bence', 'correct horse battery', 'admin');
    $users->add('kliens', 'kliens-jelszo-12', 'editor');
    $users->add('anna', 'anna-jelszo-1234', 'contributor');
    $db = new Database($app->databaseFile());
    return [$app, $db, new OAuthServer($app, $db)];
}

function oauth_post(OAuthServer $s, string $what, array $form = [], array $headers = [], string $json = ''): array
{
    $r = $s->handle(new Request('POST', [$what => ''], $form, $headers + ($json !== '' ? ['content-type' => 'application/json'] : []), '198.51.100.7', strlen($json), [], $json, true));
    return [$r->status, json_decode($r->body, true), $r];
}

function oauth_admin(App $app, Database $db, ?string $user = 'bence'): array
{
    $users = new Users($app->varDir . '/users.json');
    $session = new ArraySession($user === null ? [] : ['user' => $user, 'role' => (string) $users->role($user), 'sv' => $users->sessionVersion($user),
        'started' => time(), 'seen' => time(), 'csrf' => 'tok']);
    return [new AdminApp($app, $db, $session), $session];
}

function pkce(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

/** Register a public client, approve as $user and return [client_id, tokens]. */
function oauth_connect(App $app, Database $db, OAuthServer $s, string $user = 'bence', string $name = 'Claude'): array
{
    [, $c] = oauth_post($s, 'register', [], [], json_encode(['client_name' => $name, 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'none']));
    [$verifier, $challenge] = pkce();
    [$admin] = oauth_admin($app, $db, $user);
    $q = ['client_id' => $c['client_id'], 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code', 'state' => 'xyz',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => 'https://example.com/_lou/mcp.php'];
    $r = $admin->handle(new Request('POST', ['r' => 'authorize'], $q + ['_csrf' => 'tok', 'decision' => 'approve'], ['host' => 'example.com'], '203.0.113.9', 0, [], '', true));
    parse_str((string) parse_url(substr($r->headers['Refresh'], strlen('0; url=')), PHP_URL_QUERY), $back);
    [, $tok] = oauth_post($s, 'token', ['grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => $q['redirect_uri'],
        'client_id' => $c['client_id'], 'code_verifier' => $verifier]);
    return [$c['client_id'], $tok];
}

function oauth_mcp(App $app, Database $db, string $token, string $method = 'tools/list', array $params = []): array
{
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    $r = (new McpServer($app, $db))->handle(new Request('POST', [], [], ['authorization' => "Bearer $token", 'content-type' => 'application/json'], '198.51.100.7', strlen($body), [], $body));
    return [$r->status, json_decode($r->body, true), $r];
}

test('oauth: an MCP request without a token points to the sign-in metadata, which points to Lou itself', function () {
    [$app, $db, $s] = oauth_setup();
    $r = (new McpServer($app, $db))->handle(new Request('POST', [], [], ['content-type' => 'application/json'], '198.51.100.7', 2, [], '{}'));
    check($r->status === 401, 'needs auth');
    contains($r->headers['WWW-Authenticate'], 'resource_metadata="https://example.com/_lou/oauth.php?well-known=oauth-protected-resource"');
    $prm = json_decode($s->handle(new Request('GET', ['well-known' => 'oauth-protected-resource']))->body, true);
    check($prm['resource'] === 'https://example.com/_lou/mcp.php' && $prm['authorization_servers'] === ['https://example.com'], 'protected resource metadata');
    $as = $s->handle(new Request('GET', ['well-known' => 'oauth-authorization-server']));
    $meta = json_decode($as->body, true);
    check($meta['issuer'] === 'https://example.com' && $meta['code_challenge_methods_supported'] === ['S256'], 'server metadata');
    check($meta['registration_endpoint'] === 'https://example.com/_lou/oauth.php?register' && $meta['authorization_endpoint'] === 'https://example.com/_lou/oauth.php?authorize', 'endpoints');
    check($as->headers['Access-Control-Allow-Origin'] === '*', 'browser clients can read it');
    check(json_decode($s->handle(new Request('GET', ['well-known' => 'openid-configuration']))->body, true)['issuer'] === 'https://example.com', 'also at openid-configuration');
    // the live build ships the stub and sends the standard addresses to it
    $out = $app->root . '/public';
    check(is_file("$out/_lou/oauth.php"), 'oauth.php is in the endpoint folder');
    contains((string) file_get_contents("$out/.htaccess"), 'RewriteRule ^\.well-known/(oauth-authorization-server|oauth-protected-resource|openid-configuration)(/.*)?$ _lou/oauth.php?well-known=$1');
    // a token that is wrong (not missing) still counts as a failed attempt and says so
    $r = (new McpServer($app, $db))->handle(new Request('POST', [], [], ['authorization' => 'Bearer louat_' . str_repeat('0', 64)], '198.51.100.7', 2, [], '{}'));
    check($r->status === 401 && str_contains($r->headers['WWW-Authenticate'], 'error="invalid_token"'), 'invalid token');
});

test('oauth: register, sign in on the admin login, approve, get tokens, use the bridge in its own draft', function () {
    [$app, $db, $s] = oauth_setup();
    [$st, $c] = oauth_post($s, 'register', [], [], json_encode(['client_name' => '<b>Claude</b>', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'none']));
    check($st === 201 && str_starts_with($c['client_id'], 'louc_') && !isset($c['client_secret']) && $c['client_name'] === 'Claude', 'public client registered, name cleaned');
    [$verifier, $challenge] = pkce();
    $q = ['client_id' => $c['client_id'], 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code', 'state' => 's1',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    // oauth.php forwards to the admin's consent screen, even when the client glued its query on with a second "?"
    $r = $s->handle(new Request('GET', ['authorize?client_id' => $c['client_id']] + array_diff_key($q, ['client_id' => 1])));
    check($r->status === 302 && str_starts_with($r->headers['Location'], '/_lou/admin.php?r=authorize&') && str_contains($r->headers['Location'], 'client_id=' . $c['client_id']), $r->headers['Location'] ?? '');
    // not signed in: log in first, then come back to the consent screen
    [$anon, $session] = oauth_admin($app, $db, null);
    $r = $anon->handle(new Request('GET', ['r' => 'authorize'] + $q, [], ['host' => 'example.com']));
    check($r->status === 303 && str_ends_with($r->headers['Location'], '?r=login'), 'login first');
    $r = $anon->handle(new Request('POST', ['r' => 'login'], ['user' => 'bence', 'password' => 'correct horse battery'], ['host' => 'example.com'], '203.0.113.9', 0, [], '', true));
    check($r->status === 303 && str_contains($r->headers['Location'], '?r=authorize&') && str_contains($r->headers['Location'], 'state=s1'), 'back to the consent screen');
    $page = $anon->handle(new Request('GET', ['r' => 'authorize'] + $q, [], ['host' => 'example.com']));
    check($page->status === 200, 'consent shown');
    contains($page->body, 'Connect Claude to');
    contains($page->body, '<strong>claude.ai</strong>', 'says where the browser goes next');
    contains($page->body, 'registered itself', 'warns that the name is the app\'s own claim');
    // approve: a page that sends the browser on (a redirect after a form post would hit form-action 'self')
    $r = $anon->handle(new Request('POST', ['r' => 'authorize'], $q + ['_csrf' => $session->get('csrf'), 'decision' => 'approve'], ['host' => 'example.com'], '203.0.113.9', 0, [], '', true));
    check($r->status === 200 && str_starts_with($r->headers['Refresh'], '0; url=https://claude.ai/api/mcp/auth_callback?code='), 'sent back with a code');
    parse_str((string) parse_url(substr($r->headers['Refresh'], 7), PHP_URL_QUERY), $back);
    check($back['state'] === 's1' && $back['iss'] === 'https://example.com', 'state and issuer');
    contains($r->body, 'Continue to claude.ai');
    // wrong verifier: refused, and the code is gone
    [$st, $err] = oauth_post($s, 'token', ['grant_type' => 'authorization_code', 'code' => $back['code'], 'client_id' => $c['client_id'], 'code_verifier' => pkce()[0]]);
    check($st === 400 && $err['error'] === 'invalid_grant', 'PKCE checked');
    [$st] = oauth_post($s, 'token', ['grant_type' => 'authorization_code', 'code' => $back['code'], 'client_id' => $c['client_id'], 'code_verifier' => $verifier]);
    check($st === 400, 'a code works once, even after a failed try');
    // a second, clean round
    [, $tok] = oauth_connect($app, $db, $s);
    check(str_starts_with($tok['access_token'], 'louat_') && str_starts_with($tok['refresh_token'], 'lourt_') && $tok['expires_in'] === 3600, 'tokens issued');
    [$st, $res] = oauth_mcp($app, $db, $tok['access_token'], 'tools/call', ['name' => 'write_site_file', 'arguments' => ['path' => 'content/strings/en.json',
        'content' => json_encode(['x' => 'y'] + json_decode((string) file_get_contents($app->siteDir . '/content/strings/en.json'), true))]]);
    check($st === 200 && $res['result']['isError'] === false, 'the bridge works with the access token');
    check(Workspace::draft($app, 'llm:Claude – bence')->changedFiles() !== [], 'it works in its own draft, named after the app and the person');
    check(!str_contains((string) json_encode($db->run('SELECT * FROM oauth_tokens')->fetchAll()), $tok['access_token']), 'only hashes are stored');
});

test('oauth: refresh tokens rotate; an old one used again ends the connection', function () {
    [$app, $db] = oauth_setup();
    $now = time();
    $s = new OAuthServer($app, $db, $now);
    [$client, $tok] = oauth_connect($app, $db, $s);
    [$st, $new] = oauth_post($s, 'token', ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $client]);
    check($st === 200 && $new['refresh_token'] !== $tok['refresh_token'], 'rotated');
    check(oauth_mcp($app, $db, $new['access_token'])[0] === 200, 'new access token works');
    [$st] = oauth_post($s, 'token', ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $client]);
    check($st === 400 && oauth_mcp($app, $db, $new['access_token'])[0] === 200, 'a quick retry is refused but harmless');
    $later = new OAuthServer($app, $db, $now + 120);
    [$st] = oauth_post($later, 'token', ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $client]);
    check($st === 400 && oauth_mcp($app, $db, $new['access_token'])[0] === 401, 'reuse later ends the whole connection');
    // expired access tokens stop working
    [$client, $tok] = oauth_connect($app, $db, $s, 'kliens');
    check((new OAuth($db, new Users($app->varDir . '/users.json'), time() + 3601))->verify($tok['access_token']) === null, 'access tokens last an hour');
});

test('oauth: only editors and admins approve; a connection ends with the person\'s access, password change or a disconnect', function () {
    [$app, $db, $s] = oauth_setup();
    [, $c] = oauth_post($s, 'register', [], [], json_encode(['client_name' => 'ChatGPT', 'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect']]));
    check(str_starts_with($c['client_secret'] ?? '', 'lous_'), 'RFC 7591 default client_secret_basic gets a secret');
    [$anna] = oauth_admin($app, $db, 'anna');
    $q = ['r' => 'authorize', 'client_id' => $c['client_id'], 'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect', 'response_type' => 'code',
        'code_challenge' => pkce()[1], 'code_challenge_method' => 'S256'];
    $r = $anna->handle(new Request('GET', $q, [], ['host' => 'example.com']));
    check($r->status === 403 && str_contains($r->body, 'Only editors and administrators'), 'contributors cannot approve');

    $users = new Users($app->varDir . '/users.json');
    [, $tok] = oauth_connect($app, $db, $s, 'kliens');
    check(oauth_mcp($app, $db, $tok['access_token'])[0] === 200, 'editor connected');
    $users->setRole('kliens', 'contributor');
    check(oauth_mcp($app, $db, $tok['access_token'])[0] === 401, 'paused when the person can no longer approve');
    $users->setRole('kliens', 'editor');
    check(oauth_mcp($app, $db, $tok['access_token'])[0] === 200, 'and back');
    $users->bumpSessions('kliens');
    check(oauth_mcp($app, $db, $tok['access_token'])[0] === 401, 'a password change or "sign out everywhere" ends it');

    [, $tok] = oauth_connect($app, $db, $s, 'bence', 'Claude Desktop');
    [$admin] = oauth_admin($app, $db);
    $page = $admin->handle(new Request('GET', ['r' => 'assistants'], [], ['host' => 'example.com']))->body;
    contains($page, 'Claude Desktop – bence');
    contains($page, 'kliens', 'paused connections are listed too');
    $id = $db->run('SELECT id FROM oauth_grants WHERE name = ?', ['Claude Desktop – bence'])->fetchColumn();
    $admin->handle(new Request('POST', ['r' => 'oauth-revoke'], ['_csrf' => 'tok', 'id' => $id], ['host' => 'example.com']));
    check(oauth_mcp($app, $db, $tok['access_token'])[0] === 401, 'disconnected from the AI assistants tab');
    // RFC 7009 revocation by the app itself
    [$client, $tok] = oauth_connect($app, $db, $s, 'bence', 'Gemini');
    oauth_post($s, 'revoke', ['token' => $tok['refresh_token'], 'client_id' => $client]);
    check(oauth_mcp($app, $db, $tok['access_token'])[0] === 401, 'revoking the refresh token ends the connection');
});

test('oauth: unknown apps and unregistered return addresses are shown an error, never redirected to', function () {
    [$app, $db, $s] = oauth_setup();
    [$admin] = oauth_admin($app, $db);
    [, $c] = oauth_post($s, 'register', [], [], json_encode(['client_name' => 'X', 'redirect_uris' => ['https://x.example/cb', 'http://127.0.0.1/callback'], 'token_endpoint_auth_method' => 'none']));
    $get = fn (array $q) => $admin->handle(new Request('GET', ['r' => 'authorize', 'response_type' => 'code', 'code_challenge' => pkce()[1], 'code_challenge_method' => 'S256'] + $q, [], ['host' => 'example.com']));
    $r = $get(['client_id' => 'louc_' . str_repeat('a', 24), 'redirect_uri' => 'https://x.example/cb']);
    check($r->status === 400 && !isset($r->headers['Location']), 'unknown client: error page');
    $r = $get(['client_id' => $c['client_id'], 'redirect_uri' => 'https://evil.example/cb']);
    check($r->status === 400 && !isset($r->headers['Location']), 'foreign redirect: error page');
    check($get(['client_id' => $c['client_id'], 'redirect_uri' => 'http://127.0.0.1:53682/callback'])->status === 200, 'loopback redirects may use any port');
    // errors the app should hear go back to it
    $r = $admin->handle(new Request('GET', ['r' => 'authorize', 'response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => 'https://x.example/cb', 'state' => 'q'], [], ['host' => 'example.com']));
    check(str_starts_with($r->headers['Location'] ?? '', 'https://x.example/cb?error=invalid_request') && str_contains($r->headers['Location'], 'state=q'), 'public clients must use PKCE');
    $r = $get(['client_id' => $c['client_id'], 'redirect_uri' => 'https://x.example/cb', 'resource' => 'https://other.example/mcp']);
    check(str_contains($r->headers['Location'] ?? '', 'error=invalid_target'), 'tokens only for this site');
    // registration refuses addresses that would leak codes
    foreach (['http://x.example/cb', 'javascript:alert(1)', 'https://x.example/cb#frag', 'https://user:pw@x.example/'] as $bad) {
        [$st, $err] = oauth_post($s, 'register', [], [], json_encode(['redirect_uris' => [$bad]]));
        check($st === 400 && $err['error'] === 'invalid_redirect_uri', "refused: $bad");
    }
    check(OAuth::validRedirect('cursor://anysphere.cursor-retrieval/oauth/callback') && OAuth::validRedirect('http://localhost:33418/'), 'app schemes and localhost are fine');
    // registrations are rate limited per address
    for ($i = 0; $i < 25; $i++) {
        [$st] = oauth_post($s, 'register', [], [], json_encode(['client_name' => "n$i", 'redirect_uris' => ['https://x.example/cb']]));
    }
    check($st === 429, 'too many registrations');
});

test('oauth: admin-made app credentials (Copilot Studio manual mode) need the secret, not PKCE, and learn their return address once', function () {
    [$app, $db, $s] = oauth_setup();
    [$admin, $session] = oauth_admin($app, $db);
    $r = $admin->handle(new Request('POST', ['r' => 'oauth-client-add'], ['_csrf' => 'tok', 'name' => 'Copilot Studio', 'redirect_uri' => ''], ['host' => 'example.com']));
    check($r->status === 303, 'created');
    $page = $admin->handle(new Request('GET', ['r' => 'assistants'], [], ['host' => 'example.com']))->body;
    check(preg_match('/lous_[a-f0-9]{64}/', $page, $sm) === 1 && preg_match('/louc_[a-f0-9]{24}/', $page, $cm) === 1, 'id and secret shown once');
    contains($page, '://example.com/_lou/oauth.php?token', 'the token URL to paste');
    check(!str_contains($admin->handle(new Request('GET', ['r' => 'assistants'], [], ['host' => 'example.com']))->body, $sm[0]), 'secret not shown again');
    $cb = 'https://global.consent.azure-apim.net/redirect/abc';
    $q = ['client_id' => $cm[0], 'redirect_uri' => $cb, 'response_type' => 'code', 'state' => 'st'];
    $page = $admin->handle(new Request('GET', ['r' => 'authorize'] + $q, [], ['host' => 'example.com']));
    check($page->status === 200 && str_contains($page->body, 'first sign-in with these app credentials'), 'first use: the return address will be remembered');
    $r = $admin->handle(new Request('POST', ['r' => 'authorize'], $q + ['_csrf' => 'tok', 'decision' => 'approve'], ['host' => 'example.com'], '', 0, [], '', true));
    parse_str((string) parse_url(substr($r->headers['Refresh'], 7), PHP_URL_QUERY), $back);
    [$st, $err] = oauth_post($s, 'token', ['grant_type' => 'authorization_code', 'code' => $back['code'], 'client_id' => $cm[0], 'redirect_uri' => $cb]);
    check($st === 401 && $err['error'] === 'invalid_client', 'the secret is required');
    $r = $admin->handle(new Request('POST', ['r' => 'authorize'], $q + ['_csrf' => 'tok', 'decision' => 'approve'], ['host' => 'example.com'], '', 0, [], '', true));
    parse_str((string) parse_url(substr($r->headers['Refresh'], 7), PHP_URL_QUERY), $back);
    [$st, $tok] = oauth_post($s, 'token', ['grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => $cb],
        ['authorization' => 'Basic ' . base64_encode($cm[0] . ':' . $sm[0])]);
    check($st === 200 && oauth_mcp($app, $db, $tok['access_token'])[0] === 200, 'HTTP Basic with the secret, no PKCE');
    $r = $admin->handle(new Request('GET', ['r' => 'authorize'] + ['redirect_uri' => 'https://global.consent.azure-apim.net/redirect/other'] + $q, [], ['host' => 'example.com']));
    check($r->status === 400, 'after the first sign-in, no other return address');
    $admin->handle(new Request('POST', ['r' => 'oauth-client-delete'], ['_csrf' => 'tok', 'id' => $cm[0]], ['host' => 'example.com']));
    check(oauth_mcp($app, $db, $tok['access_token'])[0] === 401, 'deleting the credentials ends their connections');
});
