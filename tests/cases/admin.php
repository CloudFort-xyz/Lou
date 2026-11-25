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
    return new Request($method, ['r' => $route] + ($extra['query'] ?? []), $post, $headers, '203.0.113.9', 0, $files);
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
    check($r->status === 200 && body($r)['ok'] && body($r)['unpublished'] === true, $r->body);
    $data = Fs::readJson("{$app->siteDir}/content/pages/about/hu.json");
    check($data['fields']['hero']['title'] === 'Új cím', 'tags stripped: ' . $data['fields']['hero']['title']);

    $admin->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'body', 'type' => 'html', 'value' => '<p onclick="x()">Hi <script>alert(1)</script><strong>ok</strong></p>']));
    $body = Fs::readJson("{$app->siteDir}/content/pages/about/hu.json")['fields']['body'];
    check($body === '<p>Hi <strong>ok</strong></p>', "sanitised: $body");

    $token = (new Previews($app))->editor();
    $html = file_get_contents($app->outputDir() . "/_lou/p/$token/rolunk/index.html");
    contains($html, 'Új cím', 'editor preview rebuilt');
    contains($html, '/_lou/editor.js');
    contains($html, 'data-page="about" data-lang="hu"');
    contains($html, 'href="/_lou/p/' . $token . '/en/about-us/"', 'preview links stay inside the preview');
    check(!str_contains(file_get_contents($app->outputDir() . '/rolunk/index.html'), 'Új cím'), 'live site unchanged until publish');
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
    $before = file_get_contents("{$app->siteDir}/content/pages/about/hu.json");
    $r = $admin->handle(admin_req('POST', 'meta', ['page' => 'about', 'lang' => 'hu', 'slug' => 'kapcsolat']));
    check($r->status === 422, 'duplicate URL must be rejected');
    check(file_get_contents("{$app->siteDir}/content/pages/about/hu.json") === $before, 'file must be reverted');

    [$editor, , $app2] = admin_setup('editor');
    $editor->handle(admin_req('POST', 'meta', ['page' => 'about', 'lang' => 'hu', 'title' => 'X', 'slug' => 'hacked']));
    $d = Fs::readJson("{$app2->siteDir}/content/pages/about/hu.json");
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
    $field = Fs::readJson("{$app->siteDir}/content/pages/home/en.json")['fields']['hero']['image'];
    check(preg_match('#^/assets/uploads/\d{4}/szeles-kep-[a-f0-9]{8}\.webp$#', $field['src']) === 1, $field['src']);
    check($field['width'] === 1920 && $field['height'] === 640 && $field['alt'] === 'Wide image', json_encode($field));
    check(getimagesize($app->siteDir . $field['src'])[2] === IMAGETYPE_WEBP, 'not a WebP');

    $fake = sys_get_temp_dir() . '/lou-up-' . bin2hex(random_bytes(3)) . '.jpg';
    file_put_contents($fake, '<?php echo "pwned";');
    $r = $admin->handle(admin_req('POST', 'image', ['page' => 'home', 'lang' => 'en', 'field' => 'hero.image'], [],
        ['file' => ['name' => 'x.jpg', 'tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => 20]]));
    check($r->status === 422, 'non-images must be rejected');

    // alt text only
    $admin->handle(admin_req('POST', 'image', ['page' => 'home', 'lang' => 'en', 'field' => 'hero.image', 'alt' => 'New alt']));
    $f2 = Fs::readJson("{$app->siteDir}/content/pages/home/en.json")['fields']['hero']['image'];
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

    $r = $admin->handle(admin_req('POST', 'publish', [], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 200, 'publish failed');
    contains(file_get_contents($app->outputDir() . '/rolunk/index.html'), 'Publikált');
    check(is_file($app->outputDir() . "/_lou/p/$token/rolunk/index.html"), 'previews must survive publishing');
    check($admin->unpublished() === false, 'nothing unpublished after publish');
});

test('admin: rollback puts the previous build back', function () {
    [$admin, , $app] = admin_setup();
    $admin->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'hero.title', 'type' => 'text', 'value' => 'Új verzió']));
    $admin->handle(admin_req('POST', 'publish'));
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
    foreach (['pages', 'submissions', 'subscribers', 'builds', 'backups', 'redirects'] as $r) {
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
