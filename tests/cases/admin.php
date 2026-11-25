<?php

declare(strict_types=1);

use Lou\Admin\AdminApp;
use Lou\Admin\ArraySession;
use Lou\Admin\Previews;
use Lou\Admin\Users;
use Lou\App;
use Lou\Build\Builder;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\LouException;
use Lou\Support\Fs;

/** @return array{0: AdminApp, 1: ArraySession, 2: App, 3: Database} */
function admin_setup(string $role = 'admin', bool $loggedIn = true, ?int $now = null): array
{
    $root = install();
    $app = new App($root);
    (new Builder($app))->build();
    (new Users($app->varDir . '/users.json'))->add('bence', 'correct horse battery', 'admin');
    (new Users($app->varDir . '/users.json'))->add('kliens', 'kliens-jelszo-12', 'editor');
    $t = $now ?? time();
    $session = new ArraySession($loggedIn ? ['user' => $role === 'admin' ? 'bence' : 'kliens', 'role' => $role,
        'started' => $t, 'seen' => $t, 'csrf' => 'tok'] : []);
    $db = new Database($app->databaseFile());
    return [new AdminApp($app, $db, $session, $now), $session, $app, $db];
}

function admin_req(string $method, string $route, array $post = [], array $extra = [], array $files = []): Request
{
    $headers = ($extra['headers'] ?? []) + ['host' => 'example.com', 'accept' => 'application/json'];
    if ($method === 'POST' && !array_key_exists('_csrf', $post) && !isset($extra['nocsrf'])) {
        $post['_csrf'] = 'tok';
    }
    return new Request($method, ['r' => $route] + ($extra['query'] ?? []), $post, $headers, '203.0.113.9', 0, $files, https: true);
}

/** Where a user's draft lives (edits land there, not in site/). */
function draft_site(App $app, string $user = 'bence'): string
{
    return \Lou\Content\Workspace::draft($app, "user:$user")->siteDir();
}

/** Submit the user's draft to staging and publish it. */
function submit_and_publish(\Lou\Admin\AdminApp $admin, App $app, string $summary = ''): \Lou\Http\Response
{
    $admin->handle(admin_req('POST', 'submit', ['summary' => $summary]));
    $rev = (string) (\Lou\Content\Workspace::staging($app)->exists() ? \Lou\Content\Workspace::staging($app)->meta()['rev'] : '');
    return $admin->handle(admin_req('POST', 'publish', ['rev' => $rev], ['headers' => ['accept' => 'text/html']]));
}

function body(\Lou\Http\Response $r): array
{
    return json_decode($r->body, true) ?? [];
}

test('admin: login checks the password, regenerates the session and rate-limits', function () {
    [$admin, $session] = admin_setup(loggedIn: false);
    $r = $admin->handle(admin_req('POST', 'login', ['user' => 'bence', 'password' => 'nope'], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 401 && $session->get('user') === null, 'wrong password must fail');
    $r = $admin->handle(admin_req('POST', 'login', ['user' => 'Bence', 'password' => 'correct horse battery']));
    check($r->status === 303 && $session->get('user') === 'bence' && $session->get('role') === 'admin', 'login failed');
    check($session->regenerated === 1 && strlen((string) $session->get('csrf')) === 64, 'session must be regenerated with a fresh CSRF token');

    [$admin2] = admin_setup(loggedIn: false);
    for ($i = 0; $i < 10; $i++) {
        $admin2->handle(admin_req('POST', 'login', ['user' => 'bence', 'password' => 'x']));
    }
    $r = $admin2->handle(admin_req('POST', 'login', ['user' => 'bence', 'password' => 'correct horse battery']));
    check($r->status === 429, 'login must be rate limited after 10 failures, got ' . $r->status);
});

test('admin: requires login, CSRF, same origin and the right role', function () {
    [$anon] = admin_setup(loggedIn: false);
    check($anon->handle(admin_req('GET', 'pages', [], ['headers' => ['accept' => 'text/html']]))->status === 303, 'redirect to login');
    check($anon->handle(admin_req('POST', 'save'))->status === 401, 'JSON 401 for XHR');

    [$admin] = admin_setup();
    check($admin->handle(admin_req('POST', 'save', ['_csrf' => 'bad']))->status === 403, 'bad CSRF');
    check($admin->handle(admin_req('POST', 'save', [], ['headers' => ['origin' => 'https://evil.example']]))->status === 403, 'cross-origin');
    check($admin->handle(admin_req('GET', 'nope'))->status === 404, 'unknown route');

    [$editor] = admin_setup('editor');
    check($editor->handle(admin_req('GET', 'builds'))->status === 403, 'editors cannot see builds');
    check($editor->handle(admin_req('GET', 'submissions'))->status === 200, 'editors can see submissions');
});

test('admin: sessions expire after inactivity', function () {
    $t = 1_800_000_000;
    [$admin, $session] = admin_setup(now: $t);
    $session->set('seen', $t - 7201);
    check($admin->handle(admin_req('GET', 'pages', [], ['headers' => ['accept' => 'text/html']]))->status === 303, 'idle session must expire');
});

test('admin: saving text and rich text updates content and the editor preview', function () {
    [$admin, , $app] = admin_setup();
    $r = $admin->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'hero.title', 'type' => 'text', 'value' => "Új <b>cím</b>\n"]));
    check($r->status === 200 && body($r)['ok'] && body($r)['draft'] === true, $r->body);
    $data = Fs::readJson(draft_site($app) . "/content/pages/about/hu.json");
    check($data['fields']['hero']['title'] === 'Új cím', 'tags stripped: ' . $data['fields']['hero']['title']);

    $admin->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'body', 'type' => 'html', 'value' => '<p onclick="x()">Hi <script>alert(1)</script><strong>ok</strong></p>']));
    $body = Fs::readJson(draft_site($app) . "/content/pages/about/hu.json")['fields']['body'];
    check($body === '<p>Hi <strong>ok</strong></p>', "sanitised: $body");

    $token = (new Previews($app))->editor('user:bence');
    $html = file_get_contents($app->outputDir() . "/_lou/p/$token/rolunk/index.html");
    contains($html, 'Új cím', 'editor preview rebuilt');
    contains($html, '/_lou/editor.js');
    contains($html, 'data-page="about" data-lang="hu"');
    contains($html, 'href="/_lou/p/' . $token . '/en/about-us/"', 'preview links stay inside the preview');
    check(!str_contains(file_get_contents($app->outputDir() . '/rolunk/index.html'), 'Új cím'), 'live site unchanged until publish');
    check(!str_contains(file_get_contents("{$app->siteDir}/content/pages/about/hu.json"), 'Új cím'), 'site/ unchanged: edits stay in the draft');
});

test('admin: field writes are confined to the content structure', function () {
    [$admin] = admin_setup();
    $bad = [
        ['field' => '../../config', 'type' => 'text'],
        ['field' => 'hero.image', 'type' => 'text', 'page' => 'home'],   // image → text not allowed
        ['field' => 'hero.title.x', 'type' => 'text'],                  // text → object not allowed
        ['field' => 'body', 'type' => 'php'],
    ];
    foreach ($bad as $b) {
        $r = $admin->handle(admin_req('POST', 'save', $b + ['page' => 'about', 'lang' => 'hu', 'value' => 'x']));
        check($r->status === 422, 'should reject ' . json_encode($b) . ': ' . $r->status);
    }
    check($admin->handle(admin_req('POST', 'save', ['page' => '../x', 'lang' => 'hu', 'field' => 'a', 'value' => 'x']))->status === 422, 'bad page id');
});

test('admin: page settings, slug changes only for admins, duplicates reverted', function () {
    [$admin, , $app] = admin_setup();
    $r = $admin->handle(admin_req('POST', 'meta', ['page' => 'about', 'lang' => 'hu', 'title' => 'Cégünk', 'menu_label' => 'Rólunk', 'slug' => 'cegunk']));
    check(body($r)['path'] === '/cegunk/', $r->body);
    $before = file_get_contents(draft_site($app) . "/content/pages/about/hu.json");
    $r = $admin->handle(admin_req('POST', 'meta', ['page' => 'about', 'lang' => 'hu', 'slug' => 'kapcsolat']));
    check($r->status === 422, 'duplicate URL must be rejected');
    check(file_get_contents(draft_site($app) . "/content/pages/about/hu.json") === $before, 'file must be reverted');

    [$editor, , $app2] = admin_setup('editor');
    $editor->handle(admin_req('POST', 'meta', ['page' => 'about', 'lang' => 'hu', 'title' => 'X', 'slug' => 'hacked']));
    $d = Fs::readJson(draft_site($app2, 'kliens') . "/content/pages/about/hu.json");
    check($d['title'] === 'X' && $d['slug'] === 'rolunk', 'editors may change the title but not the slug');
});

test('admin: image upload is re-encoded, resized and stored as WebP', function () {
    [$admin, , $app] = admin_setup();
    $src = sys_get_temp_dir() . '/lou-up-' . bin2hex(random_bytes(3)) . '.png';
    $img = imagecreatetruecolor(3000, 1000);
    imagepng($img, $src);
    $r = $admin->handle(admin_req('POST', 'image', ['page' => 'home', 'lang' => 'en', 'field' => 'hero.image', 'alt' => 'Wide <b>image</b>'], [],
        ['file' => ['name' => 'Széles kép.PNG', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => filesize($src)]]));
    check($r->status === 200, $r->body);
    $field = Fs::readJson(draft_site($app) . "/content/pages/home/en.json")['fields']['hero']['image'];
    check(preg_match('#^/assets/uploads/\d{4}/szeles-kep-[a-f0-9]{8}\.webp$#', $field['src']) === 1, $field['src']);
    check($field['width'] === 1920 && $field['height'] === 640 && $field['alt'] === 'Wide image', json_encode($field));
    check(getimagesize(draft_site($app) . $field['src'])[2] === IMAGETYPE_WEBP, 'not a WebP');

    $fake = sys_get_temp_dir() . '/lou-up-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($fake, '<?php echo "pwned";');
    $r = $admin->handle(admin_req('POST', 'image', ['page' => 'home', 'lang' => 'en', 'field' => 'hero.image'], [],
        ['file' => ['name' => 'x.jpg', 'tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => 20]]));
    check($r->status === 422, 'non-images must be rejected');

    // alt text only
    $admin->handle(admin_req('POST', 'image', ['page' => 'home', 'lang' => 'en', 'field' => 'hero.image', 'alt' => 'New alt']));
    $f2 = Fs::readJson(draft_site($app) . "/content/pages/home/en.json")['fields']['hero']['image'];
    check($f2['alt'] === 'New alt' && $f2['src'] === $field['src'], 'alt-only update');
});

test('admin: publish updates the live site, previews survive it, share links are plain', function () {
    [$admin, , $app] = admin_setup();
    $admin->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'hero.title', 'type' => 'text', 'value' => 'Publikált']));
    $share = body($admin->handle(admin_req('POST', 'share', ['days' => '3'])));
    check($share['ok'] && preg_match('#^https://example\.com/_lou/p/[a-f0-9]{32}/$#', $share['url']) === 1, json_encode($share));
    $token = basename(parse_url($share['url'], PHP_URL_PATH));
    $shared = file_get_contents($app->outputDir() . "/_lou/p/$token/rolunk/index.html");
    check(!str_contains($shared, 'editor.js') && !str_contains($shared, 'data-lou-field'), 'share links must not include the editor');
    contains($shared, 'Publikált');

    $r = submit_and_publish($admin, $app, 'New title');
    check($r->status === 200, 'publish failed: ' . strip_tags($r->body));
    contains(file_get_contents($app->outputDir() . '/rolunk/index.html'), 'Publikált');
    check(is_file($app->outputDir() . "/_lou/p/$token/rolunk/index.html"), 'previews must survive publishing');
    check($admin->unpublished() === false, 'nothing unpublished after publish');
});

test('admin: rollback puts the previous build back', function () {
    [$admin, , $app] = admin_setup();
    $admin->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'hero.title', 'type' => 'text', 'value' => 'Új verzió']));
    submit_and_publish($admin, $app);
    contains(file_get_contents($app->outputDir() . '/rolunk/index.html'), 'Új verzió');
    $build = basename(glob($app->varDir . '/builds/before-*')[0]);
    $admin->handle(admin_req('POST', 'rollback', ['build' => $build]));
    check(!str_contains(file_get_contents($app->outputDir() . '/rolunk/index.html'), 'Új verzió'), 'old build expected');
    check($admin->handle(admin_req('POST', 'rollback', ['build' => '../../etc']))->status === 422, 'bad build name');
});

test('admin: submissions export, deletion and redirect management', function () {
    [$admin, , $app, $db] = admin_setup();
    $db->run("INSERT INTO submissions (form, lang, data, status, created_at) VALUES ('contact', 'hu', ?, 'ok', '2026-01-01T00:00:00Z')",
        [json_encode(['name' => '=HYPERLINK("x")', 'email' => 'a@b.hu'])]);
    $csv = $admin->handle(admin_req('GET', 'export'));
    contains($csv->body, "'=HYPERLINK", 'formulas must be neutralised');
    check(str_starts_with($csv->body, "\xEF\xBB\xBF"), 'UTF-8 BOM for Excel');
    check($admin->handle(admin_req('GET', 'submission', [], ['query' => ['id' => 1], 'headers' => ['accept' => 'text/html']]))->status === 200, 'detail');
    $admin->handle(admin_req('POST', 'delete-submission', ['id' => '1']));
    check((int) $db->run('SELECT COUNT(*) FROM submissions')->fetchColumn() === 0, 'deleted');

    $admin->handle(admin_req('POST', 'redirect', ['from' => '/regi-oldal/', 'action' => 'page', 'target' => 'contact|hu']));
    $admin->handle(admin_req('POST', 'redirect', ['from' => '/megszunt/', 'action' => '410']));
    $entries = Fs::readJson("{$app->siteDir}/redirects.json");
    check(count($entries) === 2, json_encode($entries));
    (new Builder($app))->build();
    $ht = file_get_contents($app->outputDir() . '/.htaccess');
    contains($ht, 'RedirectMatch 301 "^/regi\-oldal/?$" "/kapcsolat/"');
    contains($ht, 'RedirectMatch 410 "^/megszunt/?$"');
});

test('admin: every screen renders', function () {
    [$admin] = admin_setup();
    foreach (['pages', 'submissions', 'subscribers', 'builds', 'backups', 'redirects', 'review', 'history', 'assistants'] as $r) {
        $res = $admin->handle(admin_req('GET', $r, [], ['headers' => ['accept' => 'text/html']]));
        check($res->status === 200, "$r: {$res->status}");
        contains($res->headers['Content-Security-Policy'], "script-src 'self'");
    }
    $res = $admin->handle(admin_req('GET', 'edit', [], ['query' => ['page' => 'about', 'lang' => 'en'], 'headers' => ['accept' => 'text/html']]));
    check($res->status === 200, 'edit');
    contains($res->body, 'src="/_lou/p/');
    contains($res->body, '/en/about-us/"');
});

test('admin: users need strong-enough passwords and valid roles', function () {
    $u = new Users(sys_get_temp_dir() . '/lou-users-' . bin2hex(random_bytes(3)) . '.json');
    foreach ([['a', 'long-enough-pw', 'editor'], ['bence', 'short', 'editor'], ['bence', 'long-enough-pw', 'root']] as [$n, $p, $r]) {
        try {
            $u->add($n, $p, $r);
            check(false, "should reject $n/$r");
        } catch (LouException) {
        }
    }
    $u->add('bence', 'long-enough-pw', 'admin');
    check($u->verify('BENCE', 'long-enough-pw') === 'admin' && $u->verify('bence', 'wrong') === null && $u->verify('nobody', 'x') === null, 'verify');
});

test('admin: web server policy for admin.php matches the admin policy, so previews can be framed', function () {
    $root = install();
    (new Builder(new App($root)))->build();
    $htaccess = file_get_contents("$root/public/_lou/.htaccess");
    contains($htaccess, '<Files "admin.php">');
    contains($htaccess, 'Header always set Content-Security-Policy "' . AdminApp::CSP . '"');
    contains(AdminApp::CSP, "frame-src 'self'");
});

test('admin: with the example base_url, links Lou hands out use the request host, and a banner says so', function () {
    [$admin, , $app] = admin_setup();
    check(!$app->baseUrlConfigured(), 'example.com is not configured');
    $r = $admin->handle(admin_req('POST', 'share', ['path' => '/'], ['headers' => ['host' => 'lou.cloudfort.xyz']]));
    $share = json_decode($r->body, true);
    check(str_starts_with($share['url'], 'https://lou.cloudfort.xyz/_lou/p/'), $r->body);
    $page = $admin->handle(admin_req('GET', 'pages', [], ['headers' => ['accept' => 'text/html']]));
    contains($page->body, 'The site address is not set');
    contains($page->body, '?r=settings');
    check(App::class && (new Lou\Http\Request('GET', headers: ['host' => 'evil.test/x']))->origin() === '', 'malformed host is ignored');
});

test('admin: settings saves base_url, rebuilds the sitemap with it, and is admin-only', function () {
    [$admin, $session, $app, $db] = admin_setup();
    $r = $admin->handle(admin_req('POST', 'settings', ['base_url' => 'not a url']));
    check($r->status >= 400, 'invalid address rejected');
    $r = $admin->handle(admin_req('POST', 'settings', ['base_url' => 'https://lou.cloudfort.xyz/']));
    check($r->status === 303, $r->body);
    check(Fs::readJson($app->varDir . '/settings.json') === ['base_url' => 'https://lou.cloudfort.xyz'], 'saved without trailing slash');
    $fresh = new App($app->root);
    check($fresh->baseUrlConfigured() && $fresh->config['base_url'] === 'https://lou.cloudfort.xyz', 'overrides site/config.php');
    contains(file_get_contents($fresh->outputDir() . '/sitemap.xml'), 'https://lou.cloudfort.xyz/');
    $page = (new AdminApp($fresh, $db, $session))->handle(admin_req('GET', 'pages', [], ['headers' => ['accept' => 'text/html']]));
    check(!str_contains($page->body, 'The site address is not set'), 'banner gone on the next request');

    [$editor] = admin_setup('editor');
    $r = $editor->handle(admin_req('POST', 'settings', ['base_url' => 'https://x.hu']));
    check($r->status === 403, "editor got {$r->status}");
});

test('admin: the dashboard is the start screen and the side menu follows the role', function () {
    [$admin, , $app, $db] = admin_setup();
    $db->run("INSERT INTO submissions (form, lang, data, status, created_at) VALUES ('contact', 'hu', ?, 'ok', ?)",
        [json_encode(['name' => 'Kiss <Anna>', 'email' => 'anna@example.hu']), gmdate('Y-m-d H:i:s')]);
    $r = $admin->handle(new Request('GET', [], [], ['host' => 'example.com', 'accept' => 'text/html'], '203.0.113.9', https: true));
    check($r->status === 200, "dashboard got {$r->status}");
    contains($r->body, '<h1>Dashboard</h1>');
    contains($r->body, 'Kiss &lt;Anna&gt;', 'latest submissions are listed, escaped');
    contains($r->body, '<strong>1</strong> form submission');
    contains($r->body, 'href="/_lou/admin.php?r=dashboard" aria-current="page"', 'dashboard is the current menu item');
    contains($r->body, '?r=settings', 'admins see the admin-only menu');

    [$editor] = admin_setup('editor');
    $r = $editor->handle(admin_req('GET', 'dashboard', [], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 200 && !str_contains($r->body, '?r=backups') && !str_contains($r->body, '?r=assistants'), 'editors do not see admin-only items');
    contains($r->body, 'href="/_lou/admin.php?r=dashboard" aria-current="page"');
});

test('admin: every screen renders inside the shell without inline styles or scripts', function () {
    [$admin, , $app] = admin_setup();
    $admin->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'hero.title', 'type' => 'text', 'value' => 'Shell']));
    foreach (['dashboard', 'pages', 'review', 'history', 'submissions', 'subscribers', 'assistants', 'redirects', 'builds', 'backups', 'settings'] as $route) {
        $r = $admin->handle(admin_req('GET', $route, [], ['headers' => ['accept' => 'text/html']]));
        check($r->status === 200, "$route got {$r->status}");
        contains($r->body, 'class="sidebar"', "$route: no side menu");
        contains($r->body, 'class="topbar-status"', "$route: the draft indicator is missing");
        check(!preg_match('/\sstyle=|<style|<script(?![^>]*\ssrc=)/i', $r->body), "$route has inline style or script (the CSP blocks them)");
    }
    $login = admin_setup(loggedIn: false)[0]->handle(admin_req('GET', 'login', [], ['headers' => ['accept' => 'text/html']]));
    check(!str_contains($login->body, 'class="sidebar"'), 'no menu on the login screen');
});

test('admin: SMTP settings are stored owner-only, the password is never shown and empty keeps it', function () {
    [$admin, , $app] = admin_setup();
    file_put_contents($app->envFile(), "<?php return ['smtp' => ['host' => 'old.example.hu', 'pass' => 'from-env']];");
    $html = fn () => $admin->handle(admin_req('GET', 'settings', [], ['headers' => ['accept' => 'text/html']]))->body;
    contains($html(), 'value="old.example.hu"');
    contains($html(), 'Currently read from <code>.env.php</code>');
    check(!str_contains($html(), 'from-env'), 'the .env.php password must not be echoed');

    $post = ['transport' => 'smtp', 'from' => 'web@pelda.hu', 'from_name' => "Példa\r\nBcc: x@evil.test", 'host' => 'Mail.Pelda.hu',
        'port' => '465', 'secure' => 'ssl', 'user' => 'web@pelda.hu', 'pass' => ' s3cret pass '];
    $r = $admin->handle(admin_req('POST', 'mail-settings', $post));
    check($r->status === 303, $r->body);
    $secrets = Fs::readJson($app->secretsFile());
    check($secrets['smtp'] === ['host' => 'mail.pelda.hu', 'port' => 465, 'user' => 'web@pelda.hu', 'pass' => ' s3cret pass ', 'secure' => 'ssl'], json_encode($secrets));
    check((fileperms($app->secretsFile()) & 0777) === 0600, 'secrets.json must be 0600, got ' . decoct(fileperms($app->secretsFile()) & 0777));
    check(!str_contains(file_get_contents($app->varDir . '/settings.json'), 's3cret'), 'no password in settings.json');

    $fresh = new App($app->root);
    check($fresh->config['mail']['from'] === 'web@pelda.hu' && $fresh->config['mail']['from_name'] === 'Példa Bcc: x@evil.test', json_encode($fresh->config['mail']));
    check(($fresh->config['mail']['transport'] ?? '') === 'smtp', 'transport overrides site/config.php');
    check(\Lou\Env::load($fresh)->get('smtp.host') === 'mail.pelda.hu', 'the admin values win over .env.php');
    $page = (new AdminApp($fresh, new Database($fresh->databaseFile()), new ArraySession(['user' => 'bence', 'role' => 'admin', 'started' => time(), 'seen' => time(), 'csrf' => 'tok'])))
        ->handle(admin_req('GET', 'settings', [], ['headers' => ['accept' => 'text/html']]))->body;
    check(!str_contains($page, 's3cret'), 'the saved password must never be echoed');
    contains($page, 'Saved. Leave empty to keep it');

    $admin->handle(admin_req('POST', 'mail-settings', ['pass' => ''] + $post));
    check(Fs::readJson($app->secretsFile())['smtp']['pass'] === ' s3cret pass ', 'empty password keeps the stored one');
    $admin->handle(admin_req('POST', 'mail-settings', ['pass' => '', 'clear_pass' => '1'] + $post));
    check(Fs::readJson($app->secretsFile())['smtp']['pass'] === '', 'the password can be removed');

    foreach ([['host' => 'mail.pelda.hu/x'], ['port' => '0'], ['secure' => 'starttls'], ['from' => 'nope'], ['transport' => 'sendmail']] as $bad) {
        check($admin->handle(admin_req('POST', 'mail-settings', $bad + $post))->status === 422, 'rejected: ' . json_encode($bad));
    }
    [$editor] = admin_setup('editor');
    check($editor->handle(admin_req('POST', 'mail-settings', $post))->status === 403, 'admins only');
    check($editor->handle(admin_req('POST', 'mail-test', ['to' => 'a@b.hu']))->status === 403, 'admins only');
});

test('admin: the test email goes through the configured mailer and failures are shown', function () {
    [$admin, , $app] = admin_setup();
    $mailer = new ArrayMailer();
    $integrations = new \Lou\Integrations($app, \Lou\Env::load($app));
    $integrations->useMailer($mailer);
    $admin->useIntegrations($integrations);
    $r = $admin->handle(admin_req('POST', 'mail-test', ['to' => 'bence@pelda.hu'], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 200 && count($mailer->sent) === 1 && $mailer->sent[0]->to === ['bence@pelda.hu'], $r->body);
    contains($mailer->sent[0]->subject, 'Lou test email');
    contains($r->body, 'alert-ok');

    $mailer->fail = true;
    $r = $admin->handle(admin_req('POST', 'mail-test', ['to' => 'bence@pelda.hu'], ['headers' => ['accept' => 'text/html']]));
    contains($r->body, 'The test email failed.');
    contains($r->body, 'SMTP down');
    check($admin->handle(admin_req('POST', 'mail-test', ['to' => 'not-an-address']))->status === 422, 'bad address');
});

test('admin: repeatable pages (admin.repeatable) can be duplicated and deleted through the draft, others cannot', function () {
    [$admin, , $app] = admin_setup('editor');
    $site = $app->siteDir;
    file_put_contents("$site/config.php", str_replace('return [', "return [\n    'admin' => ['repeatable' => ['job']],", (string) file_get_contents("$site/config.php")));
    copy("$site/templates/page.php", "$site/templates/job.php");
    foreach (['hu' => 'allas', 'en' => 'job'] as $lang => $slug) {
        Fs::writeJson("$site/content/pages/job-a/$lang.json", ['slug' => $slug, 'parent' => 'about', 'title' => "Állás $lang", 'menu_label' => 'Gépkezelő',
            'template' => 'job', 'fields' => ['hero' => ['title' => 'Gépkezelő']]]);
    }
    [$admin, , $app] = [new \Lou\Admin\AdminApp(new App($app->root), new \Lou\Db\Database($app->databaseFile()), new ArraySession(['user' => 'kliens', 'role' => 'editor', 'started' => time(), 'seen' => time(), 'csrf' => 'tok'])), null, new App($app->root)];

    $pages = $admin->handle(admin_req('GET', 'pages', [], ['headers' => ['accept' => 'text/html']]))->body;
    check(substr_count($pages, 'r=page-duplicate') === 1 && substr_count($pages, 'r=page-delete') === 1, 'buttons only on the repeatable page');

    $r = $admin->handle(admin_req('POST', 'page-duplicate', ['page' => 'job-a'], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 303 && str_contains($r->headers['Location'] ?? '', 'page=job-a-2'), 'redirects to the copy: ' . json_encode($r->headers));
    $copy = Fs::readJson(draft_site($app, 'kliens') . '/content/pages/job-a-2/hu.json');
    check($copy['slug'] === 'allas-2' && $copy['title'] === 'Állás hu (2)' && $copy['parent'] === 'about' && $copy['template'] === 'job', json_encode($copy, JSON_UNESCAPED_UNICODE));
    check(!is_dir("$site/content/pages/job-a-2"), 'site/ untouched until publish');

    foreach (['about', 'home', 'nope'] as $bad) {
        $r = $admin->handle(admin_req('POST', 'page-delete', ['page' => $bad]));
        check($r->status === 422, "$bad must not be deletable: {$r->body}");
    }
    $r = $admin->handle(admin_req('POST', 'page-delete', ['page' => 'job-a'], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 303 && !is_dir(draft_site($app, 'kliens') . '/content/pages/job-a'), 'deleted in the draft');

    // goes live through the normal review + publish (an admin publishes)
    $admin->handle(admin_req('POST', 'submit', ['summary' => 'jobs']));
    [$boss] = [new \Lou\Admin\AdminApp($app, new \Lou\Db\Database($app->databaseFile()), new ArraySession(['user' => 'bence', 'role' => 'admin', 'started' => time(), 'seen' => time(), 'csrf' => 'tok']))];
    $rev = (string) \Lou\Content\Workspace::staging($app)->meta()['rev'];
    $boss->handle(admin_req('POST', 'publish', ['rev' => $rev], ['headers' => ['accept' => 'text/html']]));
    check(is_dir("$site/content/pages/job-a-2") && !is_dir("$site/content/pages/job-a"), 'published: copy added, original deleted');
    check(is_file($app->outputDir() . '/rolunk/allas-2/index.html'), 'copy is live');
});
