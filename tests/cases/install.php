<?php

declare(strict_types=1);

use Lou\Admin\Users;
use Lou\App;
use Lou\Build\Builder;
use Lou\Http\HttpClient;
use Lou\Http\Request;
use Lou\LouException;
use Lou\Setup\Deployer;
use Lou\Setup\Installer;
use Lou\Setup\InstallerApp;
use Lou\Setup\Release;
use Lou\Setup\Requirements;
use Lou\Setup\SiteArchive;
use Lou\Setup\Updater;
use Lou\Support\Fs;

/** A release zip of this checkout. $keys: a key pair to sign with (installs then trust its public key). */
function release_zip(string $version = '9.0.0', ?array $keys = null): string
{
    return (new Release(dirname(__DIR__, 2), $keys['public'] ?? false))->build(temp_dir(), $version, $keys['secret'] ?? null);
}

/** What a fresh unzip of a release looks like: <dir>/lou with public/install.php in it. */
function unpacked_release(): string
{
    $dir = temp_dir();
    $zip = new ZipArchive();
    $zip->open(release_zip(App::VERSION));
    $zip->extractTo($dir);
    $zip->close();
    return "$dir/lou";
}

/** Every requirement met, whatever this PHP has. */
function all_ok(string $root): Requirements
{
    return new Requirements($root, fn () => true, fn () => true, 80300, '256M');
}

function install_form(Installer $i, array $over = []): array
{
    return $over + [
        'key' => $i->key(), 'base_url' => 'https://www.pelda.hu/', 'starter' => 'example',
        'admin_user' => 'bence', 'admin_password' => 'jelszo-12345', 'admin_password2' => 'jelszo-12345',
        'mail_transport' => 'log', 'mail_from' => 'web@pelda.hu', 'mail_port' => '587', 'mail_secure' => 'tls',
    ];
}

/** Rewrites one entry of a zip. */
function zip_put(string $file, string $name, string $data): void
{
    $zip = new ZipArchive();
    $zip->open($file);
    $zip->addFromString($name, $data);
    $zip->close();
}

final class FakeReleaseHttp implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array<string, array{status: int, body: string}> $responses by URL */
    public function __construct(private readonly array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->urls[] = $url;
        return $this->responses[$url] ?? ['status' => 404, 'body' => ''];
    }
}

// ------------------------------------------------------------------------- release zips

test('release: the zip holds code, a starter and the installer, never site/, var/ or secrets', function () {
    $file = release_zip('1.2.3');
    check(basename($file) === 'lou-1.2.3.zip' && is_file("$file.sha256"), 'zip + checksum file');
    contains((string) file_get_contents("$file.sha256"), hash_file('sha256', $file));
    $zip = new ZipArchive();
    $zip->open($file);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    foreach (['lou/lou', 'lou/src/App.php', 'lou/stubs/_lou/admin.php', 'lou/vendor-bundled/phpmailer/PHPMailer.php',
              'lou/starters/example/config.php', 'lou/public/install.php', 'lou/public/index.php', 'lou/release.json'] as $n) {
        check(in_array($n, $names, true), "missing $n");
    }
    foreach ($names as $n) {
        check(!preg_match('#^lou/(site|var|tests|public/_lou|\.git)/|\.env\.php$#', $n), "must not ship $n");
    }
    contains((string) $zip->getFromName('lou/src/App.php'), "public const VERSION = '1.2.3';");
    $manifest = json_decode((string) $zip->getFromName('lou/release.json'), true);
    check($manifest['version'] === '1.2.3' && isset($manifest['files']['src/App.php']), 'manifest lists the files');
    check($zip->locateName('lou/release.sig') === false, 'not signed without a key');
    $zip->getExternalAttributesName('lou/src/App.php', $os, $attr);
    check(($attr >> 16) === 0100644, 'files are not world-writable');
    $zip->close();
});

test('release: signed zips verify; a changed, extra or unsigned file is refused', function () {
    if (!function_exists('sodium_crypto_sign_keypair')) {
        return;
    }
    $keys = Release::keyPair();
    $file = release_zip('2.0.0', $keys);
    $r = Release::extract($file, temp_dir() . '/x', $keys['public']);
    check($r['signed'] && $r['version'] === '2.0.0', 'signed release accepted');

    $other = Release::keyPair();
    try {
        Release::extract($file, temp_dir() . '/x', $other['public']);
        check(false, 'another key must fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'signature is not valid');
    }

    $cases = [
        'changed file'  => fn ($f) => zip_put($f, 'lou/src/App.php', '<?php // evil'),
        'extra file'    => fn ($f) => zip_put($f, 'lou/src/Evil.php', '<?php // evil'),
        'outside lou/'  => fn ($f) => zip_put($f, 'evil.php', '<?php'),
        'new manifest'  => function ($f) {
            $zip = new ZipArchive();
            $zip->open($f);
            $m = json_decode((string) $zip->getFromName('lou/release.json'), true);
            $m['files']['src/App.php'] = hash('sha256', '<?php // evil');
            $zip->addFromString('lou/release.json', json_encode($m));
            $zip->addFromString('lou/src/App.php', '<?php // evil');
            $zip->close();
        },
    ];
    foreach ($cases as $label => $tamper) {
        $copy = temp_dir() . '/t.zip';
        copy($file, $copy);
        $tamper($copy);
        $into = temp_dir() . '/x';
        try {
            Release::extract($copy, $into, $keys['public']);
            check(false, "$label must be refused");
        } catch (LouException) {
        }
        check(!is_dir($into), "$label: nothing left behind");
    }

    $unsigned = release_zip('2.0.0');
    try {
        Release::extract($unsigned, temp_dir() . '/x', $keys['public']);
        check(false, 'unsigned must fail when a key is trusted');
    } catch (LouException $e) {
        contains($e->getMessage(), 'not signed');
    }
    try {
        (new Release(dirname(__DIR__, 2), $keys['public']))->build(temp_dir(), '2.0.0', null);
        check(false, 'building unsigned with a trusted key must fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'LOU_SIGNING_KEY');
    }
    $smoke = (new Release(dirname(__DIR__, 2), $keys['public']))->build(temp_dir(), '2.0.0', null, true);
    check(is_file($smoke), 'an explicitly unsigned build (CI smoke test) is allowed');
    try {
        (new Release(dirname(__DIR__, 2), $keys['public']))->build(temp_dir(), '2.0.0', $other['secret']);
        check(false, 'signing with the wrong key must fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'does not match');
    }
});

// ------------------------------------------------------------------------- updates

test('update: swaps in the code from a release zip, keeps site/, var/ and .env.php, asks for a rebuild', function () {
    $target = temp_dir() . '/lou';
    (new Deployer(dirname(__DIR__, 2)))->deploy($target);
    (new Builder(new App($target)))->build();
    Fs::write("$target/site/content/marker", 'edited on the server');
    Fs::write("$target/.env.php", "<?php return [];\n");
    Fs::write("$target/src/Stale.php", 'gone in the new version');

    $u = new Updater(new App($target), new FakeReleaseHttp([]), false);
    check(!Updater::needsRebuild(new App($target)), 'fresh build matches the code');
    $r = $u->apply(release_zip('9.0.0'));
    check($r['to'] === '9.0.0' && !$r['signed'], 'updated');
    contains((string) file_get_contents("$target/src/App.php"), "'9.0.0'");
    check(!is_file("$target/src/Stale.php"), 'code replaced whole');
    check(is_file("$target/site/content/marker") && is_file("$target/.env.php") && is_file("$target/public/index.html"), 'site, secrets and live build kept');
    check(is_dir("$target/starters/example"), 'starters come along');
    check(!is_dir("$target/site/../var/updates") || glob("$target/var/updates/.unpacked-*") === [], 'nothing left unpacked');
    // (needsRebuild is seen by the next request, which loads the new App class: covered in the admin test)

    try {
        $u->apply(release_zip('0.0.1'));
        check(false, 'older versions need force');
    } catch (LouException $e) {
        contains($e->getMessage(), 'older');
    }
    $u->apply(release_zip('0.0.1'), true);

    Fs::writeJson("$target/var/deployed-from.json", ['source' => dirname(__DIR__, 2)]);
    try {
        $u->apply(release_zip('9.0.0'));
        check(false, 'installs deployed from a clone update from the clone');
    } catch (LouException $e) {
        contains($e->getMessage(), 'deployed from the Git clone');
    }
    unlink("$target/var/deployed-from.json");

    mkdir("$target/.git");
    try {
        $u->apply(release_zip('9.0.0'));
        check(false, 'git checkouts update with git');
    } catch (LouException $e) {
        contains($e->getMessage(), 'Git checkout');
    }
});

test('update: checks GitHub for the latest release only when asked, then downloads that asset', function () {
    $root = install();
    $zip = release_zip('9.1.0');
    $api = 'https://api.github.com/repos/' . Updater::REPO . '/releases/latest';
    $asset = 'https://github.com/' . Updater::REPO . '/releases/download/v9.1.0/lou-9.1.0.zip';
    $http = new FakeReleaseHttp([
        $api   => ['status' => 200, 'body' => json_encode(['tag_name' => 'v9.1.0', 'html_url' => 'https://github.com/x', 'published_at' => '2026-11-25T00:00:00Z',
                   'assets' => [['name' => 'lou-9.1.0.zip.sha256', 'browser_download_url' => "$asset.sha256"], ['name' => 'lou-9.1.0.zip', 'browser_download_url' => $asset]]])],
        $asset => ['status' => 200, 'body' => (string) file_get_contents($zip)],
    ]);
    $u = new Updater(new App($root), $http, false);
    check($http->urls === [], 'no request until asked');
    try {
        $u->download();
        check(false, 'download needs a check first');
    } catch (LouException) {
    }
    $c = $u->check();
    check($c['version'] === '9.1.0' && $c['newer'] && $c['url'] === $asset, 'latest release found');
    check($u->lastCheck()['version'] === '9.1.0', 'remembered');
    $file = $u->download();
    check(hash_file('sha256', $file) === hash_file('sha256', $zip), 'downloaded the zip');

    $none = new Updater(new App($root), new FakeReleaseHttp([$api => ['status' => 200, 'body' => json_encode(['tag_name' => 'v9.2.0', 'assets' => []])]]), false);
    try {
        $none->check();
        check(false, 'a release without the zip fails');
    } catch (LouException $e) {
        contains($e->getMessage(), 'no lou-9.2.0.zip');
    }
});

test('admin: Updates applies an uploaded release, then the banner asks for a rebuild', function () {
    [$admin, , $app] = admin_setup();
    $admin->useUpdater(new Updater($app, new FakeReleaseHttp([]), false));
    $html = ['headers' => ['accept' => 'text/html']];
    contains($admin->handle(admin_req('GET', 'updates', [], $html))->body, 'Check for updates');
    $zip = release_zip('9.0.0');
    $r = $admin->handle(admin_req('POST', 'update-upload', [], $html, ['release' => ['tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'name' => 'lou-9.0.0.zip']]));
    check($r->status === 303, 'redirects after updating: ' . $r->status . ' ' . strip_tags($r->body));
    contains((string) file_get_contents($app->root . '/src/App.php'), "'9.0.0'");
    contains($admin->handle(admin_req('GET', 'updates', [], $html))->body, 'Updated from');
    // this process still runs the old App class, so it sees the live build as current: fake an older build
    editJson($app->varDir . '/live.json', fn ($d) => ['lou' => '0.0.1'] + $d);
    contains($admin->handle(admin_req('GET', 'dashboard', [], $html))->body, 'Rebuild now');
    $admin->handle(admin_req('POST', 'rebuild', [], $html));
    check(!Updater::needsRebuild($app), 'rebuilt');

    [$editor] = admin_setup('editor');
    check($editor->handle(admin_req('GET', 'updates', [], $html))->status === 403, 'editors cannot update');
});

// ------------------------------------------------------------------------- installer

test('installer: needs the setup key, builds the site, creates the admin last and locks itself', function () {
    $root = unpacked_release();
    $i = new Installer($root, all_ok($root));
    check(!$i->installed() && isset($i->starters()['example']), 'example starter offered');
    check(!$i->buildsIntoDocroot("$root/public"), 'served from lou/public');

    try {
        $i->install(install_form($i, ['key' => 'AAAA-BBBB-CCCC-DDDD']), null, "$root/public");
        check(false, 'a wrong key must fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'setup key');
    }
    try {
        $i->install(install_form($i, ['admin_password2' => 'something-else']), null, "$root/public");
        check(false, 'mismatched passwords must fail');
    } catch (LouException) {
    }
    try {
        $i->install(install_form($i, ['mail_transport' => 'smtp', 'mail_host' => '']), null, "$root/public");
        check(false, 'SMTP without a server must fail');
    } catch (LouException) {
    }
    check(!is_dir("$root/site") && !$i->installed(), 'failed attempts change nothing');

    $key = $i->key();
    $r = $i->install(install_form($i, ['key' => strtolower(str_replace('-', ' ', $key))]), null, "$root/public");
    check($r['site'] === 'https://www.pelda.hu', 'address normalised');
    check(is_file("$root/site/config.php") && is_file("$root/public/index.html") && is_file("$root/public/_lou/admin.php"), 'site seeded and built');
    check(!is_file("$root/public/install.php"), 'the build replaced the installer');
    check(glob("$root/var/builds/before-*") === [], 'the installer is not kept as a build to roll back to');
    check((new Users("$root/var/users.json"))->verify('bence', 'jelszo-12345') === 'admin', 'admin created');
    check(Fs::readJson("$root/var/settings.json")['base_url'] === 'https://www.pelda.hu', 'address saved');
    check(!is_file($i->keyFile()) && $i->installed(), 'key removed, installer locked');
    try {
        $i->install(install_form($i), null, "$root/public");
        check(false, 'second install must fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'already installed');
    }
});

test('installer: a fixed public_html gets the site built into it, the host files stay', function () {
    $root = unpacked_release();
    $docroot = dirname($root) . '/public_html';
    Fs::write("$docroot/cgi-bin/x.cgi", 'host');
    Fs::write("$docroot/index.html", 'default page');
    Fs::write("$docroot/.htaccess", "# BEGIN cPanel-generated php ini directives, do not edit\nAddHandler application/x-httpd-ea-php83 .php\n# END cPanel-generated php ini directives, do not edit\n");
    copy("$root/public/install.php", "$docroot/install.php");

    $i = new Installer($root, all_ok($root));
    check($i->buildsIntoDocroot($docroot), 'detects the fixed docroot');
    $r = $i->install(install_form($i), null, $docroot);
    $settings = Fs::readJson("$root/var/settings.json");
    check($settings['output_swap'] === 'contents' && $settings['output_dir'] === realpath($docroot), 'builds into public_html');
    check(is_file("$docroot/rolunk/index.html") && is_file("$docroot/cgi-bin/x.cgi"), 'site built, host files kept');
    check(str_contains((string) file_get_contents("$docroot/index.html"), '<html'), 'the default page was replaced');
    check(glob("$root/var/replaced/*/index.html") !== [], '…and kept in var/replaced/');
    check((bool) array_filter($r['warnings'], fn ($w) => str_contains($w, 'var/replaced')), 'and reported');

    // rebuilds keep the panel block once, and drop pages Lou no longer builds
    $app = new App($root);
    Fs::removeDir("$root/site/content/pages/services");
    file_put_contents("$root/site/menu.json", '["home", "about", "contact"]');
    (new Builder($app))->build();
    (new Builder($app))->build();
    $ht = (string) file_get_contents("$docroot/.htaccess");
    check(substr_count($ht, 'AddHandler application/x-httpd-ea-php83') === 1, "panel block kept once:\n$ht");
    contains($ht, 'DirectoryIndex index.html');
    check(!is_dir("$docroot/szolgaltatasok") || is_file("$docroot/szolgaltatasok/index.html") && str_contains((string) file_get_contents("$docroot/szolgaltatasok/index.html"), 'refresh'), 'removed page is only a redirect stub');
    check(is_file("$docroot/cgi-bin/x.cgi") && is_file("$docroot/install.php"), 'foreign files survive rebuilds (the web installer deletes itself)');
    check(Requirements::passed(array_filter((new Requirements($root))->run(), fn ($c) => $c['label'] === 'output folder')), 'check accepts the contents mode');
});

test('installer: seeds site/ from an uploaded GitHub-style zip or a Lou backup', function () {
    // "Download ZIP" of a client repository: kner.hu-lou-main/site/…, plus files that are not the site
    $zipFile = temp_dir() . '/kner.hu-lou-main.zip';
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $zip->addFromString('kner.hu-lou-main/README.md', 'client repo');
    $zip->addFromString('kner.hu-lou-main/lou/site/config.php', '<?php return [];');   // the submodule's example: deeper, ignored
    $zip->addFromString('kner.hu-lou-main/lou/site/content/x', 'x');
    foreach (Fs::files(dirname(__DIR__, 2) . '/site') as $rel) {
        $zip->addFromString("kner.hu-lou-main/site/$rel", (string) file_get_contents(dirname(__DIR__, 2) . "/site/$rel"));
    }
    $zip->close();

    $root = unpacked_release();
    $i = new Installer($root, all_ok($root));
    $i->install(install_form($i, ['starter' => 'upload']), $zipFile, "$root/public");
    check(is_file("$root/site/menu.json") && !is_file("$root/site/README.md") && !is_dir("$root/site/lou"), 'took the shallowest site folder only');
    check(is_file("$root/public/rolunk/index.html"), 'built');

    // a Lou backup brings its submissions database along
    $app = new App($root);
    (new Lou\Db\Database($app->databaseFile()))->run("INSERT INTO submissions (form, lang, data, status, created_at) VALUES ('contact', 'hu', '{}', 'ok', '2026-01-01')");
    $backup = (new Lou\Backup\Backup($app))->create();
    $root2 = unpacked_release();
    $i2 = new Installer($root2, all_ok($root2));
    $i2->install(install_form($i2, ['starter' => 'upload']), $backup, "$root2/public");
    check((int) (new PDO("sqlite:$root2/var/lou.sqlite"))->query('SELECT COUNT(*) FROM submissions')->fetchColumn() === 1, 'database restored');

    foreach (['no site' => ['readme.txt' => 'x'], 'traversal' => ['site/config.php' => '<?php return [];', 'site/content/x' => 'x', 'site/../../evil.php' => 'x']] as $label => $entries) {
        $bad = temp_dir() . '/bad.zip';
        $zip = new ZipArchive();
        $zip->open($bad, ZipArchive::CREATE);
        foreach ($entries as $n => $d) {
            $zip->addFromString($n, $d);
        }
        $zip->close();
        $dest = temp_dir() . '/site';
        try {
            SiteArchive::extract($bad, $dest, temp_dir());
            check(false, "$label must fail");
        } catch (LouException) {
        }
        check(!is_dir($dest), "$label: nothing left behind");
    }
});

test('installer web page: cookie-bound form, strict CSP, no script; locked after installing', function () {
    $root = unpacked_release();
    $installer = new Installer($root, all_ok($root));
    $app = new InstallerApp($root, "$root/public", "$root/public/install.php", $installer);
    $get = $app->handle(new Request('GET', [], [], ['host' => 'pelda.hu']));
    check($get->status === 200, 'form shown');
    contains($get->headers['Content-Security-Policy'], "default-src 'none'");
    check(!str_contains($get->body, '<script') && !str_contains($get->body, 'style='), 'no inline script or style');
    contains($get->body, 'install-key.txt');
    preg_match('/lou_install=([a-f0-9]{64})/', $get->headers['Set-Cookie'], $m);
    $token = $m[1];
    contains($get->body, 'value="' . $token . '"');
    check(str_contains($app->handle(new Request('GET', ['asset' => 'admin.css']))->body, '.install-wrap'), 'stylesheet served');

    $post = install_form($installer) + ['_csrf' => $token];
    $noCookie = $app->handle(new Request('POST', [], $post, ['host' => 'pelda.hu']));
    check($noCookie->status === 403 && !$installer->installed(), 'no cookie, no install');
    $cross = $app->handle(new Request('POST', [], $post, ['host' => 'pelda.hu', 'cookie' => "lou_install=$token", 'origin' => 'https://evil.example']));
    check($cross->status === 403, 'cross-site post refused');
    $bad = $app->handle(new Request('POST', [], ['admin_password' => 'x'] + $post, ['host' => 'pelda.hu', 'cookie' => "a=b; lou_install=$token"]));
    check($bad->status === 422 && !str_contains($bad->body, 'jelszo-12345'), 'errors shown, passwords not echoed');
    $ok = $app->handle(new Request('POST', [], $post, ['host' => 'pelda.hu', 'cookie' => "a=b; lou_install=$token", 'origin' => 'https://pelda.hu']));
    check($ok->status === 200 && str_contains($ok->body, 'Open the admin'), 'installed: ' . strip_tags($ok->body));
    check($app->handle(new Request('GET', [], [], ['host' => 'pelda.hu']))->status === 404, 'locked afterwards');
});

// ------------------------------------------------------------------------- site design from a zip

/** A GitHub-style zip of a client repo: <top>/site/… with $changes applied (path => contents, null = delete). */
function client_zip(string $siteDir, array $changes): string
{
    $file = temp_dir() . '/client-main.zip';
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE);
    $files = [];
    foreach (Fs::files($siteDir) as $rel) {
        $files[$rel] = (string) file_get_contents("$siteDir/$rel");
    }
    foreach ($changes as $rel => $data) {
        if ($data === null) {
            unset($files[$rel]);
        } else {
            $files[$rel] = $data;
        }
    }
    foreach ($files as $rel => $data) {
        $zip->addFromString("client-main/site/$rel", $data);
    }
    $zip->close();
    return $file;
}

test('admin: a client site zip puts template and asset changes into the draft, content stays, then publish records them', function () {
    [$admin, , $app] = admin_setup();
    $html = ['headers' => ['accept' => 'text/html']];
    $site = $app->siteDir;
    $page = (string) file_get_contents("$site/templates/page.php");
    $zip = client_zip($site, [
        'templates/page.php'           => $page . "\n<!-- v2 -->\n",
        'assets/css/new.css'           => 'body{color:red}',
        'templates/evil.php'           => '<?php system("id"); ?>',
        'content/pages/about/hu.json'  => '{"title":"from the repo"}',
        'menu.json'                    => '["home"]',
        'assets/uploads/photo.webp'    => 'repo copy of an upload',
    ]);
    $r = $admin->handle(admin_req('POST', 'design-upload', [], $html, ['site' => ['tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'name' => 'client-main.zip']]));
    check($r->status === 200, strip_tags($r->body));
    contains($r->body, 'are in your draft');
    contains($r->body, 'templates/evil.php', 'unsafe template reported');
    $draft = draft_site($app);
    contains((string) file_get_contents("$draft/templates/page.php"), '<!-- v2 -->');
    check(is_file("$draft/assets/css/new.css") && !is_file("$draft/templates/evil.php"), 'safe files in, unsafe out');
    check(!str_contains((string) file_get_contents("$draft/content/pages/about/hu.json"), 'from the repo'), 'content untouched');
    check((string) file_get_contents("$draft/menu.json") !== '["home"]' && !is_file("$draft/assets/uploads/photo.webp"), 'menu and uploads untouched');
    check(!str_contains((string) file_get_contents("$site/templates/page.php"), 'v2'), 'nothing live yet');

    $res = submit_and_publish($admin, $app, 'design from zip');
    check($res->status === 200, strip_tags($res->body));
    contains((string) file_get_contents("$site/templates/page.php"), '<!-- v2 -->');
    $manifest = Fs::readJson($app->varDir . '/deploy-design.json');
    check(($manifest['templates/page.php'] ?? '') === sha1_file("$site/templates/page.php"), 'published files become the baseline');
    check(!is_file($app->varDir . '/deploy-design.pending.json'), 'nothing pending');

    // a later zip: a template changed on the server since is kept, untouched ones follow the zip
    file_put_contents("$site/templates/home.php", (string) file_get_contents("$site/templates/home.php") . "\n<!-- server edit -->\n");
    $zip2 = client_zip($site, ['templates/home.php' => '<?php $v->layout("layout") ?>repo', 'assets/css/new.css' => 'body{color:blue}', 'templates/evil.php' => null]);
    $r = $admin->handle(admin_req('POST', 'design-upload', [], $html, ['site' => ['tmp_name' => $zip2, 'error' => UPLOAD_ERR_OK, 'name' => 'z.zip']]));
    contains($r->body, 'Skipped');
    check(str_contains((string) file_get_contents("$draft/templates/home.php"), 'server edit'), 'server change kept');
    check((string) file_get_contents("$draft/assets/css/new.css") === 'body{color:blue}', 'unchanged-on-server asset updated');

    [$editor] = admin_setup('editor');
    check($editor->handle(admin_req('POST', 'design-upload', [], $html, ['site' => ['tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'name' => 'z.zip']]))->status === 403, 'admins only');
});

test('design import: a rejected import does not move the baseline; an installer seeded from a zip records one', function () {
    $root = install();
    $app = new App($root);
    Fs::writeJson("$root/var/deploy-design.json", Lou\Setup\Deployer::designHashes($app->siteDir));
    $before = (string) file_get_contents("$root/var/deploy-design.json");
    $draft = Lou\Content\Workspace::draft($app, 'user:bence');
    $r = (new Lou\Setup\DesignImport($app))->toDraft(client_zip($app->siteDir, ['assets/css/site.css' => 'x{}']), $draft);
    check($r['written'] === ['assets/css/site.css'] && !$r['first'], 'one change');
    $draft->discard();
    Lou\Setup\DesignImport::confirm($app);   // someone publishes something else
    check((string) file_get_contents("$root/var/deploy-design.json") === $before, 'baseline unchanged while the import is not live');
    $r = (new Lou\Setup\DesignImport($app))->toDraft(client_zip($app->siteDir, ['assets/css/site.css' => 'x{}']), Lou\Content\Workspace::draft($app, 'user:bence'));
    check($r['written'] === ['assets/css/site.css'] && $r['skipped'] === [], 'the same zip can be imported again');

    $zipRoot = unpacked_release();
    $i = new Installer($zipRoot, all_ok($zipRoot));
    $i->install(install_form($i, ['starter' => 'upload']), client_zip(dirname(__DIR__, 2) . '/site', []), "$zipRoot/public");
    check(isset(Fs::readJson("$zipRoot/var/deploy-design.json")['templates/page.php']), 'installer records the design baseline');
});
