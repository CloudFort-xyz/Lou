<?php

declare(strict_types=1);

use Lou\Admin\Previews;
use Lou\App;
use Lou\Bridge\McpServer;
use Lou\Bridge\PathPolicy;
use Lou\Bridge\Staging;
use Lou\Bridge\TemplateGuard;
use Lou\Bridge\Tokens;
use Lou\Bridge\Tools;
use Lou\Build\Builder;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\LouException;
use Lou\Support\Diff;
use Lou\Support\Fs;

/** @return array{0: McpServer, 1: string, 2: App, 3: Database} */
function bridge_setup(): array
{
    $root = install();
    $app = new App($root);
    (new Builder($app))->build();
    $token = (new Tokens($app->varDir . '/tokens.json'))->create('Claude test');
    $db = new Database($app->databaseFile());
    return [new McpServer($app, $db), $token, $app, $db];
}

function rpc(McpServer $mcp, string $token, array|string $msg, array $headers = [], array $query = []): array
{
    $body = is_string($msg) ? $msg : json_encode($msg);
    $r = $mcp->handle(new Request('POST', $query, [], $headers + ['authorization' => "Bearer $token", 'content-type' => 'application/json'], '198.51.100.4', strlen($body), [], $body));
    return [$r->status, json_decode($r->body, true), $r];
}

function call_tool(McpServer $mcp, string $token, string $name, array $args = []): array
{
    [, $res] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $args]]);
    return $res['result'];
}

test('bridge: template guard allows view helpers and blocks code execution', function () {
    foreach (glob(dirname(__DIR__, 2) . '/site/templates/{,partials/}*.php', GLOB_BRACE) as $f) {
        check(TemplateGuard::check(file_get_contents($f)) === [], basename($f) . ' should pass: ' . implode('; ', TemplateGuard::check(file_get_contents($f))));
    }
    check(TemplateGuard::check('<?php foreach ($v->field("items", []) as $i): ?><li><?= $v->e(strtoupper($i["t"])) ?></li><?php endforeach ?>') === [], 'loops and string functions are fine');
    $bad = [
        '<?php system("id") ?>', '<?php eval("1") ?>', '<?php include "/etc/passwd" ?>', '<?php $f = "system"; $f("id") ?>',
        '<?php echo $_GET["x"] ?>', '<?php new SplFileObject("x") ?>', '<?php App::x() ?>', '<?php `id` ?>',
        '<?php file_get_contents("/etc/passwd") ?>', '<?php $$a ?>', '<?php $x->y() ?>', '<?php $v->{"a"}() ?>',
        '<?php array_map("system", ["id"]) ?>', '<?php (function () {})() ?>', '<?php fn() => 1 ?>', '<?php if ( ?>',
    ];
    foreach ($bad as $code) {
        check(TemplateGuard::check($code) !== [], "should be rejected: $code");
    }
});

test('bridge: path policy keeps writes inside site content, templates and assets', function () {
    foreach (['../src/App.php', '/etc/passwd', '.env.php', 'templates/.htaccess', 'content/../../x', 'árvíz.txt'] as $p) {
        try {
            PathPolicy::checkWrite(PathPolicy::normalise($p));
            check(false, "$p should be refused");
        } catch (LouException) {
        }
    }
    foreach (['assets/js/x.php', 'content/pages/a/hu.php', 'assets/x.exe', 'other/x.css'] as $p) {
        try {
            PathPolicy::checkWrite(PathPolicy::normalise($p));
            check(false, "$p should be refused");
        } catch (LouException) {
        }
    }
    PathPolicy::checkWrite('templates/partials/hero.php');
    PathPolicy::checkWrite('assets/css/site.css');
    check(PathPolicy::checkContent('content/x.json', '{bad') !== [], 'invalid JSON');
    check(PathPolicy::checkContent('assets/i.svg', '<svg onload="x()"/>') !== [], 'SVG handlers');
    check(PathPolicy::checkContent('config.php', '<?php return 5;') !== [], 'config must return an array');
    check(PathPolicy::checkContent('assets/a.css', "\xff\xfe") !== [], 'invalid UTF-8');
});

test('bridge: diff shows changed lines with context', function () {
    $d = Diff::unified("a\nb\nc\nd\n", "a\nB\nc\nd\ne\n");
    contains($d, "-b\n+B");
    contains($d, '+e');
    contains($d, '@@ -1 +1 @@');
    check(Diff::unified("x\n", "x\n") === '', 'no diff for equal input');
});

test('bridge: MCP needs a valid token, blocks foreign origins and rate-limits failures', function () {
    [$mcp, $token, $app] = bridge_setup();
    $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
    check(rpc($mcp, 'lou_' . str_repeat('0', 48), $init)[0] === 401, 'wrong token');
    [$status, , $r] = rpc($mcp, '', $init);
    check($status === 401 && str_contains($r->headers['WWW-Authenticate'], 'Bearer'), 'missing token');
    check(rpc($mcp, $token, $init, ['origin' => 'https://evil.example'])[0] === 403, 'foreign origin');
    check(rpc($mcp, $token, $init, ['origin' => 'https://example.com'])[0] === 200, 'own origin');
    check(rpc($mcp, '', $init, [], ['key' => $token])[0] === 200, '?key= fallback');
    check($mcp->handle(new Request('GET', [], [], ['authorization' => "Bearer $token"]))->status === 405, 'GET is 405');

    $ids = array_keys((new Tokens($app->varDir . '/tokens.json'))->all());
    (new Tokens($app->varDir . '/tokens.json'))->revoke($ids[0]);
    check(rpc($mcp, $token, $init)[0] === 401, 'revoked token');
    for ($i = 0; $i < 20; $i++) {
        rpc($mcp, 'nope', $init);
    }
    check(rpc($mcp, 'nope', $init)[0] === 429, 'auth failures are rate limited');
});

test('bridge: MCP initialize, tools/list, batches, notifications and errors', function () {
    [$mcp, $token] = bridge_setup();
    [$s, $res] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']]]);
    check($s === 200 && $res['result']['protocolVersion'] === '2025-03-26', 'negotiates the client version');
    check(isset($res['result']['capabilities']['tools']) && $res['result']['serverInfo']['name'] === 'lou', 'capabilities');
    [, $res] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
    check($res['result']['protocolVersion'] === McpServer::PROTOCOL_VERSIONS[0], 'falls back to the latest version');

    check(rpc($mcp, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])[0] === 202, 'notification → 202');

    [, $res] = rpc($mcp, $token, [['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'tools/list'], ['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'nope'],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']]);
    check(count($res) === 2, 'batch answers requests only');
    $names = array_column($res[0]['result']['tools'], 'name');
    check(in_array('write_file', $names, true) && in_array('build_preview', $names, true), 'tools listed');
    check($res[1]['error']['code'] === -32601, 'unknown method');
    check(rpc($mcp, $token, '{oops')[1]['error']['code'] === -32700, 'parse error');

    $r = call_tool($mcp, $token, 'read_file', ['path' => '../.env.php']);
    check($r['isError'] === true, 'tool errors are results with isError');
});

test('bridge: assistant edits staging, previews, submits; approve applies and publishes', function () {
    [$mcp, $token, $app, $db] = bridge_setup();
    $info = json_decode(call_tool($mcp, $token, 'describe_site')['content'][0]['text'], true);
    check($info['languages'] === ['hu', 'en'] && str_contains($info['guide'], 'write_file'), 'describe_site');

    $css = call_tool($mcp, $token, 'read_file', ['path' => 'assets/css/site.css'])['content'][0]['text'];
    $r = call_tool($mcp, $token, 'write_file', ['path' => 'assets/css/site.css', 'content' => $css . "\n.lou-test{color:red}\n"]);
    check($r['isError'] === false, 'write css: ' . $r['content'][0]['text']);
    editJson($app->root . '/var/staging/site/content/pages/about/hu.json', fn ($d) => ['slug' => 'cegunkrol'] + $d);
    $r = call_tool($mcp, $token, 'write_file', ['path' => 'templates/page.php', 'content' => '<?php system("id") ?>']);
    check($r['isError'] === true && str_contains($r['content'][0]['text'], 'system'), 'unsafe template refused');

    check(!str_contains(file_get_contents($app->siteDir . '/assets/css/site.css'), 'lou-test'), 'site/ untouched while staged');
    check(!str_contains(file_get_contents($app->outputDir() . '/assets/css/site.css'), 'lou-test'), 'live untouched while staged');

    $p = json_decode(call_tool($mcp, $token, 'build_preview')['content'][0]['text'], true);
    check($p['ok'] === true && str_contains($p['url'], '/_lou/p/'), 'preview url');
    check($p['redirects']['moved'][0]['from'] === '/rolunk/', 'redirect report in the preview');
    $dir = $app->outputDir() . parse_url($p['url'], PHP_URL_PATH);
    check(is_file($dir . 'cegunkrol/index.html'), 'preview built from staging');

    $changes = json_decode(call_tool($mcp, $token, 'get_changes')['content'][0]['text'], true);
    check(count($changes['files']['modified']) === 2 && str_contains($changes['diffs']['assets/css/site.css'], '+.lou-test'), 'changes + diff');
    $r = call_tool($mcp, $token, 'submit_for_review', ['summary' => 'Red test class, shorter slug']);
    check($r['isError'] === false && (new Staging($app))->meta()['status'] === 'review', 'submitted');

    // the review screen
    [$admin] = [new Lou\Admin\AdminApp($app, $db, new Lou\Admin\ArraySession(['user' => 'bence', 'role' => 'admin', 'started' => time(), 'seen' => time(), 'csrf' => 'tok']))];
    $html = $admin->handle(admin_req('GET', 'changes', [], ['headers' => ['accept' => 'text/html']]))->body;
    contains($html, 'Red test class');
    contains($html, '/rolunk/');
    contains($html, 'assets/css/site.css');

    $r = $admin->handle(admin_req('POST', 'approve', ['id' => 'stale'], ['headers' => ['accept' => 'text/html']]));
    contains($r->body, 'changed while you were reviewing');
    $r = $admin->handle(admin_req('POST', 'approve', ['id' => (new Staging($app))->meta()['id']], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 200, 'approve: ' . strip_tags($r->body));
    check(str_contains(file_get_contents($app->siteDir . '/assets/css/site.css'), 'lou-test'), 'applied to site/');
    check(is_file($app->outputDir() . '/cegunkrol/index.html'), 'published');
    contains(file_get_contents($app->outputDir() . '/.htaccess'), '"^/rolunk/?$" "/cegunkrol/"');
    check(!(new Staging($app))->exists(), 'staging cleared');
    check(!array_filter((new Previews($app))->all(), fn ($p) => $p['kind'] === 'changeset'), 'changeset preview revoked');
    check(count(glob($app->varDir . '/backups/*.zip')) === 1, 'safety backup made');
    $log = $db->run("SELECT COUNT(*) FROM audit_log WHERE source = 'llm' AND actor = 'llm:Claude test'")->fetchColumn();
    check($log >= 2, 'assistant writes are audit-logged');
});

test('bridge: admin edits made meanwhile are kept; same-file edits conflict', function () {
    [$mcp, $token, $app] = bridge_setup();
    $s = new Staging($app);
    $s->write('content/pages/about/en.json', json_encode(['title' => 'About (LLM)'] + Fs::readJson($app->siteDir . '/content/pages/about/en.json')), 'llm:t');
    // meanwhile in the admin: a different file, and a build that adds an automatic redirect
    editJson($app->siteDir . '/content/pages/contact/hu.json', fn ($d) => ['title' => 'Kapcsolat (admin)'] + $d);
    Fs::writeJson($app->siteDir . '/redirects.json', [['from' => '/regi/', 'to' => ['page' => 'home', 'lang' => 'hu'], 'status' => 301]]);
    check($s->conflicts() === [], 'no conflict for different files');
    $s->apply();
    check(Fs::readJson($app->siteDir . '/content/pages/contact/hu.json')['title'] === 'Kapcsolat (admin)', 'admin edit kept');
    check(Fs::readJson($app->siteDir . '/content/pages/about/en.json')['title'] === 'About (LLM)', 'LLM edit applied');

    $s->write('content/pages/about/en.json', json_encode(['title' => 'Again'] + Fs::readJson($app->siteDir . '/content/pages/about/en.json')), 'llm:t');
    $s->write('redirects.json', json_encode([['from' => '/kampany/', 'to' => '/en/', 'status' => 301]]), 'llm:t');
    editJson($app->siteDir . '/content/pages/about/en.json', fn ($d) => ['title' => 'Admin wins?'] + $d);
    check($s->conflicts() === ['content/pages/about/en.json'], 'conflict detected');
    try {
        $s->apply();
        check(false, 'conflict must block apply');
    } catch (LouException $e) {
        contains($e->getMessage(), 'about/en.json');
    }
    $s->apply(true);
    check(Fs::readJson($app->siteDir . '/content/pages/about/en.json')['title'] === 'Again', 'force applies');
    // redirects: the LLM removed /regi/ (present in base) and added /kampany/
    $froms = array_column(Fs::readJson($app->siteDir . '/redirects.json'), 'from');
    check($froms === ['/kampany/'], 'redirects merged: ' . implode(',', $froms));
});

test('bridge: redirects added live meanwhile survive an LLM redirect change', function () {
    [, , $app] = bridge_setup();
    $s = new Staging($app);
    $s->write('redirects.json', json_encode([['from' => '/kampany/', 'to' => '/en/', 'status' => 301]]), 'llm:t');
    Fs::writeJson($app->siteDir . '/redirects.json', [['from' => '/auto/', 'to' => ['page' => 'home', 'lang' => 'hu'], 'status' => 301]]);
    $s->apply();
    check(array_column(Fs::readJson($app->siteDir . '/redirects.json'), 'from') === ['/auto/', '/kampany/'], 'both kept');
});

test('bridge: invalid staged sites cannot be previewed or approved; reject discards', function () {
    [$mcp, $token, $app, $db] = bridge_setup();
    call_tool($mcp, $token, 'write_file', ['path' => 'menu.json', 'content' => '["home", "nope"]']);
    $p = json_decode(call_tool($mcp, $token, 'build_preview')['content'][0]['text'], true);
    check($p['ok'] === false && str_contains(implode(' ', $p['problems']), 'nope'), 'preview reports problems');
    check(call_tool($mcp, $token, 'submit_for_review', ['summary' => 'x'])['isError'] === true, 'cannot submit');
    try {
        (new Staging($app))->apply();
        check(false, 'apply must fail');
    } catch (LouException) {
    }
    check(call_tool($mcp, $token, 'delete_file', ['path' => 'config.php'])['isError'] === true, 'config.php cannot be deleted');

    [$admin] = admin_setup();
    $admin = new Lou\Admin\AdminApp($app, $db, new Lou\Admin\ArraySession(['user' => 'bence', 'role' => 'admin', 'started' => time(), 'seen' => time(), 'csrf' => 'tok']));
    $admin->handle(admin_req('POST', 'reject', [], ['headers' => ['accept' => 'text/html']]));
    check(!(new Staging($app))->exists(), 'rejected');
    check(Fs::readJson($app->siteDir . '/menu.json') !== ['home', 'nope'], 'site untouched');
});

test('bridge: images are re-encoded into staging', function () {
    [$mcp, $token, $app] = bridge_setup();
    $img = imagecreatetruecolor(40, 20);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    $r = call_tool($mcp, $token, 'upload_image', ['filename' => 'Logó.png', 'data_base64' => base64_encode($png)]);
    check($r['isError'] === false, 'upload: ' . $r['content'][0]['text']);
    $res = json_decode($r['content'][0]['text'], true);
    check(str_ends_with($res['src'], '.webp') && is_file($app->root . '/var/staging/site/' . ltrim(str_replace('/assets/', 'assets/', $res['src']), '/')), 'webp in staging: ' . $res['src']);
    check(call_tool($mcp, $token, 'upload_image', ['filename' => 'x.png', 'data_base64' => base64_encode('<?php x')])['isError'] === true, 'non-image refused');
});

test('bridge: REST fallback and OpenAPI', function () {
    [$mcp, $token] = bridge_setup();
    [$s, $res] = rpc($mcp, $token, ['path' => 'menu.json'], [], ['tool' => 'read_file']);
    check($s === 200 && $res['ok'] === true && str_contains($res['result'], 'home'), 'REST read');
    [$s, $res] = rpc($mcp, $token, ['path' => '../x'], [], ['tool' => 'read_file']);
    check($s === 422 && $res['ok'] === false, 'REST error');
    check(rpc($mcp, $token, [], [], ['tool' => 'rm_rf'])[0] === 404, 'unknown tool');
    $spec = json_decode($mcp->handle(new Request('GET', ['openapi' => '']))->body, true);
    check($spec['openapi'] === '3.1.0' && isset($spec['paths']['/_lou/mcp.php?tool=write_file']), 'OpenAPI lists the tools');
});

test('bridge: assistants screen creates a token once and revokes it; live build ships mcp.php', function () {
    [$admin, , $app] = admin_setup();
    $r = $admin->handle(admin_req('POST', 'token-add', ['name' => 'Claude – Bence'], ['headers' => ['accept' => 'text/html']]));
    check(preg_match('/lou_[a-f0-9]{48}/', $r->body, $m) === 1, 'token shown');
    check((new Tokens($app->varDir . '/tokens.json'))->verify($m[0]) === 'Claude – Bence', 'token works');
    check(!str_contains((string) file_get_contents($app->varDir . '/tokens.json'), $m[0]), 'only the hash is stored');
    $page = $admin->handle(admin_req('GET', 'assistants', [], ['headers' => ['accept' => 'text/html']]))->body;
    check(!str_contains($page, $m[0]), 'token not shown again');
    contains($page, 'claude mcp add');
    $id = array_key_first((new Tokens($app->varDir . '/tokens.json'))->all());
    $admin->handle(admin_req('POST', 'token-revoke', ['id' => $id]));
    check((new Tokens($app->varDir . '/tokens.json'))->verify($m[0]) === null, 'revoked');

    [$editor] = admin_setup('editor');
    check($editor->handle(admin_req('GET', 'changes'))->status === 403, 'editors cannot review LLM changes');
    check($editor->handle(admin_req('GET', 'assistants'))->status === 403, 'editors cannot manage tokens');

    $stub = (string) file_get_contents($app->outputDir() . '/_lou/mcp.php');
    contains($stub, 'Lou\Bridge\Endpoint::run(');
    check(!str_contains($stub, '__LOU_ROOT__'), 'root substituted');
});

test('bridge: approved changesets are committed to Git when enabled', function () {
    if (trim((string) shell_exec('command -v git')) === '') {
        return; // no git on this machine
    }
    $root = install();
    file_put_contents("$root/site/config.php", str_replace("'base_url'", "'git' => ['commit' => true],\n    'base_url'", file_get_contents("$root/site/config.php")));
    $git = fn (string ...$a) => trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $a)) . ' 2>&1'));
    $git('init', '-q');
    $git('-c', 'user.name=t', '-c', 'user.email=t@t', 'add', 'site');
    $git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'init');
    $app = new App($root);
    (new Builder($app))->build();
    $db = new Database($app->databaseFile());
    (new Staging($app))->write('assets/css/site.css', "body{color:#123}\n", 'llm:Claude');
    (new Staging($app))->submit('Darker text');
    $admin = new Lou\Admin\AdminApp($app, $db, new Lou\Admin\ArraySession(['user' => 'bence', 'role' => 'admin', 'started' => time(), 'seen' => time(), 'csrf' => 'tok']));
    $r = $admin->handle(admin_req('POST', 'approve', ['id' => (new Staging($app))->meta()['id']], ['headers' => ['accept' => 'text/html']]));
    contains($r->body, 'Committed to Git');
    contains($git('log', '-1', '--format=%s|%an'), 'Darker text|bence');
    check($git('status', '--porcelain', '--', 'site') === '', 'site/ is clean after the commit');
});
