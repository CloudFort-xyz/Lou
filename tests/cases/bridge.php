<?php

declare(strict_types=1);

use Lou\Admin\Previews;
use Lou\App;
use Lou\Bridge\McpServer;
use Lou\Bridge\PathPolicy;
use Lou\Content\Workspace;
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
    check(TemplateGuard::check('<?= $v->e($v->site->page("contact", $v->lang())?->menuLabel) ?><?= $v->e($v->site->globals($v->lang())["company"] ?? "") ?>') === [], 'site pages and globals are fine');
    $bad = [
        '<?php system("id") ?>', '<?php eval("1") ?>', '<?php include "/etc/passwd" ?>', '<?php $f = "system"; $f("id") ?>',
        '<?php echo $_GET["x"] ?>', '<?php new SplFileObject("x") ?>', '<?php App::x() ?>', '<?php `id` ?>',
        '<?php file_get_contents("/etc/passwd") ?>', '<?php $$a ?>', '<?php $x->y() ?>', '<?php $v->{"a"}() ?>',
        '<?php array_map("system", ["id"]) ?>', '<?php (function () {})() ?>', '<?php fn() => 1 ?>', '<?php if ( ?>',
        // callees that are values, not names (each one ran phpinfo() before the guard knew about them)
        "<?php 'phpinfo'() ?>", '<?php "phpinfo"() ?>', "<?php ('php' . 'info')() ?>", "<?php \$a = ['phpinfo']; \$a[0]() ?>",
        "<?php ['Lou\\\\Support\\\\Fs', 'files']('/') ?>", '<?php $f = "phpinfo"; "{$f}"() ?>', "<?= \$v->field('f')() ?>",
        "<?php \$x = <<<EOT\nphpinfo\nEOT;\n(<<<EOT\nphpinfo\nEOT)() ?>", '<?php $c = strlen(...) ?>',
        // namespace\\x() resolves to \\x() in a template (found by the nightly review)
        '<?php namespace\\system("id") ?>', '<?= namespace\\strtoupper("a") ?>', '<?php echo namespace\\PHP_VERSION ?>',
        // Lou's internals: config and server paths could be printed into public pages
        '<?= $v->e($v->site->app->root) ?>', '<?= $v->e(implode(",", array_keys($v->site->app->config))) ?>', '<?= $v->e($v->site?->app->varDir) ?>',
    ];
    foreach ($bad as $code) {
        check(TemplateGuard::check($code) !== [], "should be rejected: $code");
    }
});

test('bridge: redirects.json entries cannot inject .htaccess lines or write outside the build', function () {
    $root = install();
    $app = new App($root);
    $bad = [
        [['from' => '/a', 'to' => "/b\"\nAddHandler application/x-httpd-php .css\n#"]],
        [['from' => "/a\nOptions +Indexes\n", 'status' => 410]],
        [['from' => '/../../escaped', 'to' => 'https://example.org/']],
        [['from' => '/a', 'to' => 'javascript:alert(1)']],
    ];
    foreach ($bad as $entries) {
        Fs::writeJson("$root/site/redirects.json", $entries);
        try {
            (new Builder($app))->build();
            check(false, 'should fail: ' . json_encode($entries));
        } catch (LouException $e) {
            contains($e->getMessage(), 'Invalid redirects');
        }
        check(!is_dir(dirname($root, 2) . '/escaped'), 'no file outside the build');
    }
    Fs::writeJson("$root/site/redirects.json", [['from' => '/régi-oldal/', 'to' => 'https://example.org/a?b=1&c=2']]);
    (new Builder($app))->build();
    contains(file_get_contents($app->outputDir() . '/.htaccess'), '"https://example.org/a?b=1&c=2"', 'normal URLs and UTF-8 paths still work');
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
    check(in_array('write_site_file', $names, true) && in_array('build_preview', $names, true), 'tools listed');
    check($res[1]['error']['code'] === -32601, 'unknown method');
    check(rpc($mcp, $token, '{oops')[1]['error']['code'] === -32700, 'parse error');

    $r = call_tool($mcp, $token, 'read_site_file', ['path' => '../.env.php']);
    check($r['isError'] === true, 'tool errors are results with isError');
});

function editor_admin(App $app, Database $db, string $user = 'bence', string $role = 'admin'): Lou\Admin\AdminApp
{
    // sessions are only valid for users that exist (the role is re-read from users.json on every request)
    $users = new Lou\Admin\Users($app->varDir . '/users.json');
    if ($users->role($user) !== $role) {
        $users->add($user, 'test-password-123', $role);
    }
    return new Lou\Admin\AdminApp($app, $db, new Lou\Admin\ArraySession(['user' => $user, 'role' => $role, 'started' => time(), 'seen' => time(), 'csrf' => 'tok']));
}

test('bridge: assistant edits its draft, previews, submits to staging; publishing makes it live', function () {
    [$mcp, $token, $app, $db] = bridge_setup();
    $info = json_decode(call_tool($mcp, $token, 'describe_site')['content'][0]['text'], true);
    check($info['languages'] === ['hu', 'en'] && str_contains($info['guide'], 'write_site_file'), 'describe_site');

    $css = call_tool($mcp, $token, 'read_site_file', ['path' => 'assets/css/site.css'])['content'][0]['text'];
    $r = call_tool($mcp, $token, 'write_site_file', ['path' => 'assets/css/site.css', 'content' => $css . "\n.lou-test{color:red}\n"]);
    check($r['isError'] === false, 'write css: ' . $r['content'][0]['text']);
    $about = json_decode(call_tool($mcp, $token, 'read_site_file', ['path' => 'content/pages/about/hu.json'])['content'][0]['text'], true);
    call_tool($mcp, $token, 'write_site_file', ['path' => 'content/pages/about/hu.json', 'content' => json_encode(['slug' => 'cegunkrol'] + $about, JSON_UNESCAPED_UNICODE)]);
    $r = call_tool($mcp, $token, 'write_site_file', ['path' => 'templates/page.php', 'content' => '<?php system("id") ?>']);
    check($r['isError'] === true && str_contains($r['content'][0]['text'], 'system'), 'unsafe template refused');

    check(!Workspace::staging($app)->exists(), 'nothing on staging before submitting');
    check(!str_contains(file_get_contents($app->siteDir . '/assets/css/site.css'), 'lou-test'), 'site/ untouched');

    $p = json_decode(call_tool($mcp, $token, 'build_preview')['content'][0]['text'], true);
    check($p['ok'] === true && str_contains($p['url'], '/_lou/p/'), 'preview url');
    check($p['redirects']['moved'][0]['from'] === '/rolunk/', 'redirect report in the preview');
    check(is_file($app->outputDir() . parse_url($p['url'], PHP_URL_PATH) . 'cegunkrol/index.html'), 'preview built from the draft');

    // a person's draft doesn't see the assistant's unsubmitted work
    $admin = editor_admin($app, $db);
    check(!str_contains(file_get_contents(Workspace::draft($app, 'user:bence')->app()->siteDir . '/assets/css/site.css'), 'lou-test'), 'drafts are private');

    $changes = json_decode(call_tool($mcp, $token, 'get_changes')['content'][0]['text'], true);
    check(count($changes['files']['modified']) === 2 && str_contains($changes['diffs']['assets/css/site.css'], '+.lou-test'), 'changes + diff');
    check(call_tool($mcp, $token, 'submit_for_review', ['summary' => ''])['isError'] === true, 'summary required');
    $r = call_tool($mcp, $token, 'submit_for_review', ['summary' => 'Red test class, shorter slug']);
    check($r['isError'] === false, 'submit: ' . $r['content'][0]['text']);
    $staging = Workspace::staging($app);
    check($staging->changedFiles() === ['assets/css/site.css', 'content/pages/about/hu.json'], 'on staging: ' . implode(',', $staging->changedFiles()));
    check(!Workspace::draft($app, 'llm:Claude test')->exists(), 'assistant draft emptied');
    check(str_contains(file_get_contents(Workspace::draft($app, 'user:bence')->app()->siteDir . '/assets/css/site.css'), 'lou-test'), 'drafts see staging');

    // review + publish (an editor may publish)
    $editor = editor_admin($app, $db, 'kliens', 'editor');
    $html = $editor->handle(admin_req('GET', 'review', [], ['headers' => ['accept' => 'text/html']]))->body;
    contains($html, 'Red test class');
    contains($html, 'Claude test (assistant)');
    contains($html, 'assets/css/site.css');
    $editor->handle(admin_req('POST', 'review-preview'));
    contains($editor->handle(admin_req('GET', 'review', [], ['headers' => ['accept' => 'text/html']]))->body, '/rolunk/');

    $r = $editor->handle(admin_req('POST', 'publish', ['rev' => 'stale'], ['headers' => ['accept' => 'text/html']]));
    contains($r->body, 'changed while you were reviewing');
    $r = $editor->handle(admin_req('POST', 'publish', ['rev' => (string) $staging->meta()['rev']], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 200, 'publish: ' . strip_tags($r->body));
    check(str_contains(file_get_contents($app->siteDir . '/assets/css/site.css'), 'lou-test'), 'applied to site/');
    check(is_file($app->outputDir() . '/cegunkrol/index.html'), 'published');
    contains(file_get_contents($app->outputDir() . '/.htaccess'), '"^/rolunk/?$" "/cegunkrol/"');
    check(!Workspace::staging($app)->exists(), 'staging cleared');
    check(count(glob($app->varDir . '/backups/*.zip')) === 1, 'safety backup made');
    $log = $db->run("SELECT COUNT(*) FROM audit_log WHERE source = 'llm' AND actor = 'llm:Claude test'")->fetchColumn();
    check($log >= 2, 'assistant writes are audit-logged');
});

test('bridge: invalid drafts cannot be previewed or submitted', function () {
    [$mcp, $token, $app] = bridge_setup();
    call_tool($mcp, $token, 'write_site_file', ['path' => 'menu.json', 'content' => '["home", "nope"]']);
    $p = json_decode(call_tool($mcp, $token, 'build_preview')['content'][0]['text'], true);
    check($p['ok'] === false && str_contains(implode(' ', $p['problems']), 'nope'), 'preview reports problems');
    check(call_tool($mcp, $token, 'submit_for_review', ['summary' => 'x'])['isError'] === true, 'cannot submit');
    check(!Workspace::staging($app)->exists(), 'nothing staged');
    check(call_tool($mcp, $token, 'delete_site_file', ['path' => 'config.php'])['isError'] === true, 'config.php cannot be deleted');
    call_tool($mcp, $token, 'discard_changes');
    check(!Workspace::draft($app, 'llm:Claude test')->exists(), 'discarded');
});

test('bridge: images are re-encoded into the draft', function () {
    [$mcp, $token, $app] = bridge_setup();
    $img = imagecreatetruecolor(40, 20);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    $r = call_tool($mcp, $token, 'upload_site_image', ['filename' => 'Logó.png', 'data_base64' => base64_encode($png)]);
    check($r['isError'] === false, 'upload: ' . $r['content'][0]['text']);
    $res = json_decode($r['content'][0]['text'], true);
    check(str_ends_with($res['src'], '.webp') && is_file(Workspace::draft($app, 'llm:Claude test')->siteDir() . $res['src']), 'webp in the draft: ' . $res['src']);
    check(call_tool($mcp, $token, 'upload_site_image', ['filename' => 'x.png', 'data_base64' => base64_encode('<?php x')])['isError'] === true, 'non-image refused');
});

test('bridge: REST fallback and OpenAPI', function () {
    [$mcp, $token] = bridge_setup();
    [$s, $res] = rpc($mcp, $token, ['path' => 'menu.json'], [], ['tool' => 'read_site_file']);
    check($s === 200 && $res['ok'] === true && str_contains($res['result'], 'home'), 'REST read');
    [$s, $res] = rpc($mcp, $token, ['path' => '../x'], [], ['tool' => 'read_site_file']);
    check($s === 422 && $res['ok'] === false, 'REST error');
    check(rpc($mcp, $token, [], [], ['tool' => 'rm_rf'])[0] === 404, 'unknown tool');
    $spec = json_decode($mcp->handle(new Request('GET', ['openapi' => '']))->body, true);
    check($spec['openapi'] === '3.1.0' && isset($spec['paths']['/_lou/mcp.php?tool=write_site_file']), 'OpenAPI lists the tools');
});

test('bridge: assistants screen creates a token once and revokes it; live build ships mcp.php', function () {
    [$admin, , $app] = admin_setup();
    $r = $admin->handle(admin_req('POST', 'token-add', ['name' => 'Claude – Bence'], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 303 && str_ends_with($r->headers['Location'], '?r=assistants'), 'post/redirect/get, so a reload does not create a second token');
    $r = $admin->handle(admin_req('GET', 'assistants', [], ['headers' => ['accept' => 'text/html']]));
    check(preg_match('/lou_[a-f0-9]{48}/', $r->body, $m) === 1, 'token shown');
    contains($r->body, 'data-copy="new-token"');
    check(substr_count($r->body, $m[0]) >= 5, 'the snippets contain the new token');
    check((new Tokens($app->varDir . '/tokens.json'))->verify($m[0]) === 'Claude – Bence', 'token works');
    check(!str_contains((string) file_get_contents($app->varDir . '/tokens.json'), $m[0]), 'only the hash is stored');
    $page = $admin->handle(admin_req('GET', 'assistants', [], ['headers' => ['accept' => 'text/html']]))->body;
    check(!str_contains($page, $m[0]), 'token not shown again');
    contains($page, 'claude mcp add');
    contains($page, 'lou_YOUR_TOKEN');
    check(substr_count($page, 'data-copy="snip-') === 13, 'every snippet has a copy button');
    contains($page, 'Copilot Studio');
    contains($page, 'parameter name <code>key</code>', 'Copilot Studio sends the token as ?key=');
    contains($page, '${input:lou-token}', 'the VS Code config prompts for the token instead of storing it');
    // (string): a token id of only digits becomes an int array key, and the form always posts a string
    $id = (string) array_key_first((new Tokens($app->varDir . '/tokens.json'))->all());
    $admin->handle(admin_req('POST', 'token-revoke', ['id' => $id]));
    contains($admin->handle(admin_req('GET', 'assistants', [], ['headers' => ['accept' => 'text/html']]))->body, 'The token was revoked');
    check((new Tokens($app->varDir . '/tokens.json'))->verify($m[0]) === null, 'revoked');

    [$editor] = admin_setup('editor');
    check($editor->handle(admin_req('GET', 'review'))->status === 200, 'editors can review and publish');
    check($editor->handle(admin_req('GET', 'assistants'))->status === 403, 'editors cannot manage tokens');

    $stub = (string) file_get_contents($app->outputDir() . '/_lou/mcp.php');
    contains($stub, 'Lou\Bridge\Endpoint::run(');
    check(!str_contains($stub, '__LOU_ROOT__'), 'root substituted');
});


test('bridge: tool arguments in the shapes other clients send (Copilot Studio, OpenAPI connectors)', function () {
    [$mcp, $token, $app] = bridge_setup();
    $read = static function (array $params) use ($mcp, $token): array {
        [, $res] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'read_site_file'] + $params]);
        return $res['result'];
    };
    $ok = static fn (array $r): bool => $r['isError'] === false && str_contains($r['content'][0]['text'], 'home');
    $shapes = [
        'spec' => ['arguments' => ['path' => 'menu.json']],
        'JSON string' => ['arguments' => '{"path": "menu.json"}'],
        'camelCase' => ['arguments' => ['filePath' => 'menu.json']],
        'snake_case' => ['arguments' => ['file_path' => 'menu.json']],
        'capitalised' => ['arguments' => ['Path' => 'menu.json']],
        'wrapped object' => ['arguments' => ['body' => ['path' => 'menu.json']]],
        'wrapped string' => ['arguments' => ['input' => '{"path":"menu.json"}']],
        'input key' => ['input' => ['path' => 'menu.json']],
        'next to name' => ['path' => 'menu.json'],
        'value object' => ['arguments' => ['path' => ['value' => 'menu.json']]],
        'extra keys' => ['arguments' => ['path' => 'menu.json', 'encoding' => 'utf-8']],
    ];
    foreach ($shapes as $label => $params) {
        check($ok($read($params)), "read_site_file with arguments as $label");
    }

    // declared names win over aliases, and an alias never takes another parameter's value
    check(Tools::normaliseArguments('write_site_file', ['path' => 'a.json', 'file' => 'b.json', 'content' => '{}']) === ['path' => 'a.json', 'content' => '{}'], 'declared name wins');
    check(Tools::normaliseArguments('upload_site_image', ['filename' => 'a.png', 'data' => 'QQ==']) === ['filename' => 'a.png', 'data_base64' => 'QQ=='], 'upload aliases');
    check(Tools::normaliseArguments('write_site_file', ['path' => 'a.json', 'content' => ['title' => 'Á']])['content'] === "{\n    \"title\": \"Á\"\n}\n", 'JSON content sent as an object');
    check(Tools::normaliseArguments('describe_site', ['anything' => 1]) === [], 'no-argument tools ignore extras');
    check(Tools::normaliseArguments('list_site_files', '') === [], 'empty string is no arguments');

    // missing arguments name what arrived (keys and types, never values) and are logged for diagnosis
    $r = $read(['arguments' => ['where' => 'secret-value']]);
    check($r['isError'] === true, 'still an error');
    contains($r['content'][0]['text'], "Missing argument 'path' for read_site_file");
    contains($r['content'][0]['text'], '"where": string');
    check(!str_contains($r['content'][0]['text'], 'secret-value'), 'values are not echoed');
    contains((string) file_get_contents($app->varDir . '/error.log'), 'mcp read_site_file: unusable arguments');
    $r = $read(['arguments' => ['a', 'b']]);
    check($r['isError'] === true && str_contains($r['content'][0]['text'], 'must be a JSON object'), 'a list is refused');

    // the REST fallback goes through the same mapping
    [$s, $res] = rpc($mcp, $token, ['filePath' => 'menu.json'], [], ['tool' => 'read_site_file']);
    check($s === 200 && $res['ok'] === true, 'REST with another parameter name');
    [$s, $res] = rpc($mcp, $token, 'not json', [], ['tool' => 'read_site_file']);
    check($s === 422 && $res['ok'] === false, 'REST with a broken body');

    // the file tools were renamed (Copilot Studio sends read_file to its own file reader); old names still work, unlisted
    [, $list] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
    $names = array_column($list['result']['tools'], 'name');
    check(in_array('read_site_file', $names, true) && !array_intersect(array_keys(Tools::OLD_NAMES), $names), 'only the new names are listed');
    check($ok(call_tool($mcp, $token, 'read_file', ['path' => 'menu.json'])), 'read_file still works');
    check(call_tool($mcp, $token, 'list_files', ['prefix' => 'content'])['isError'] === false, 'list_files still works');
    [$s] = rpc($mcp, $token, ['path' => 'menu.json'], [], ['tool' => 'read_file']);
    check($s === 200, 'old name on the REST fallback');
});

test('bridge: assistants read the publish history (when, what, who submitted, who published)', function () {
    [$mcp, $token, $app, $db] = bridge_setup();
    $json = static fn (array $r): array => json_decode($r['content'][0]['text'], true) ?? ['raw' => $r['content'][0]['text']];

    $empty = $json(call_tool($mcp, $token, 'list_site_updates'));
    check($empty['updates'] === [] && $empty['history_enabled'] === true && $empty['waiting_for_review'] === null, 'nothing published yet');
    check(call_tool($mcp, $token, 'get_site_update', ['id' => 'latest'])['isError'] === true, 'no latest yet');

    // 1) the assistant changes a page title and submits; editor "kliens" publishes
    $about = json_decode(call_tool($mcp, $token, 'read_file', ['path' => 'content/pages/about/en.json'])['content'][0]['text'], true);
    call_tool($mcp, $token, 'write_file', ['path' => 'content/pages/about/en.json', 'content' => json_encode(['title' => 'Who we are'] + $about, JSON_UNESCAPED_UNICODE)]);
    call_tool($mcp, $token, 'submit_for_review', ['summary' => 'Friendlier About title']);
    $waiting = $json(call_tool($mcp, $token, 'list_site_updates'))['waiting_for_review'];
    check($waiting['files'] === ['content/pages/about/en.json'] && $waiting['submissions'][0]['by'] === ['name' => 'Claude test', 'type' => 'assistant'], 'waiting for review');
    $kliens = editor_admin($app, $db, 'kliens', 'editor');
    $r = $kliens->handle(admin_req('POST', 'publish', ['rev' => (string) Workspace::staging($app)->meta()['rev']], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 200, 'publish 1');

    // 2) admin "bence" changes a template himself and publishes
    $bence = editor_admin($app, $db);
    $d = Workspace::draft($app, 'user:bence');
    $d->write('templates/page.php', $d->read('templates/page.php') . "<!-- v2 -->\n");
    check(submit_and_publish($bence, $app, 'Template marker')->status === 200, 'publish 2');

    $list = $json(call_tool($mcp, $token, 'list_site_updates'));
    check(count($list['updates']) === 2 && $list['more'] === 0, 'two updates: ' . json_encode($list));
    [$second, $first] = $list['updates'];
    check($second['published_by'] === ['name' => 'bence', 'type' => 'person'] && $second['files'] === ['templates/page.php' => 'changed'], 'newest first');
    check($first['published_by'] === ['name' => 'kliens', 'type' => 'person'], 'publisher recorded');
    check($first['submissions'][0]['by'] === ['name' => 'Claude test', 'type' => 'assistant'] && $first['submissions'][0]['summary'] === 'Friendlier About title'
        && $first['submissions'][0]['files'] === ['content/pages/about/en.json'], 'submitter, summary and files recorded');
    check(str_starts_with($first['build'] ?? '', '20'), 'live build id recorded');
    check($list['waiting_for_review'] === null, 'nothing waiting after publish');

    // details: plain-language fields, diffs only on request
    $d = $json(call_tool($mcp, $token, 'get_site_update', ['id' => $first['id']]));
    $c = $d['changes'][0];
    check($c['path'] === 'content/pages/about/en.json' && $c['kind'] === 'changed' && str_contains($c['label'], 'English'), 'change label: ' . json_encode($c));
    check(in_array(['field' => 'Title', 'old' => $about['title'], 'new' => 'Who we are'], $c['fields'], true), 'field old → new: ' . json_encode($c['fields']));
    check(!isset($c['diff']), 'no diff unless asked');
    $d = $json(call_tool($mcp, $token, 'get_site_update', ['id' => 'latest', 'diff' => true]));
    check($d['id'] === $second['id'], 'latest');
    contains($d['changes'][0]['diff'], '+<!-- v2 -->');

    // file filter and time filter
    $only = $json(call_tool($mcp, $token, 'list_site_updates', ['path' => 'content/pages/about/en.json']));
    check(count($only['updates']) === 1 && $only['updates'][0]['id'] === $first['id'] && !isset($only['other_events']), 'per-file history');
    check($json(call_tool($mcp, $token, 'list_site_updates', ['since' => 'tomorrow']))['updates'] === [], 'since in the future');
    check(count($json(call_tool($mcp, $token, 'list_site_updates', ['since' => '2000-01-01', 'limit' => 1]))['updates']) === 1, 'limit');
    check($json(call_tool($mcp, $token, 'list_site_updates', ['limit' => 1]))['more'] === 1, 'more counts the rest');
    $r = call_tool($mcp, $token, 'list_site_updates', ['since' => 'not a date at all']);
    check($r['isError'] === true && str_contains($r['content'][0]['text'], 'Could not read since'), 'bad since');
    $r = call_tool($mcp, $token, 'get_site_update', ['id' => '../../etc/passwd']);
    check($r['isError'] === true && str_contains($r['content'][0]['text'], 'Unknown update id'), 'bad id');
    $r = call_tool($mcp, $token, 'get_site_update', ['id' => $first['id'], 'path' => 'templates/page.php']);
    check($r['isError'] === true && str_contains($r['content'][0]['text'], 'did not change'), 'path not in update');

    // other live-site events: a rollback
    $build = basename(glob($app->varDir . '/builds/before-*')[0]);
    $bence->handle(admin_req('POST', 'rollback', ['build' => $build]));
    $ev = $json(call_tool($mcp, $token, 'list_site_updates'))['other_events'];
    check($ev[0]['what'] === 'rollback' && $ev[0]['by'] === ['name' => 'bence', 'type' => 'person'] && $ev[0]['detail'] === $build, 'rollback event: ' . json_encode($ev));

    // argument shapes Copilot Studio and connectors send
    [, $res] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_site_update',
        'arguments' => ['body' => ['updateId' => $second['id'], 'includeDiff' => 'true', 'filePath' => 'templates/page.php']]]]);
    check($res['result']['isError'] === false && str_contains($res['result']['content'][0]['text'], '+<!-- v2 -->'), 'wrapped camelCase arguments');
    [, $res] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_site_updates', 'arguments' => '{"count": "1"}']]);
    check(count(json_decode($res['result']['content'][0]['text'], true)['updates']) === 1, 'limit as a string alias in a JSON string');
    [$s, $rest] = rpc($mcp, $token, ['id' => 'latest'], [], ['tool' => 'get_site_update']);
    check($s === 200 && $rest['result']['id'] === $second['id'], 'REST fallback');

    // read-only: marked so, not audit-logged, and nothing secret in the output
    [, $tools] = rpc($mcp, $token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
    $byName = array_column($tools['result']['tools'], null, 'name');
    check(($byName['list_site_updates']['annotations']['readOnlyHint'] ?? false) === true && isset($byName['write_site_file']) && !isset($byName['write_site_file']['annotations']), 'readOnlyHint');
    check((int) $db->run("SELECT COUNT(*) FROM audit_log WHERE action IN ('list_site_updates', 'get_site_update')")->fetchColumn() === 0, 'reads are not logged');
    $all = json_encode([$list, $d, $ev]);
    check(!str_contains($all, $token) && !str_contains($all, 'lou_'), 'no tokens in the history output');
});
