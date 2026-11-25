<?php

declare(strict_types=1);

/**
 * Dependency-free test runner: php tests/run.php
 * Each test gets a fresh copy of the example site in a temp install root.
 */

use Lou\App;
use Lou\Backup\Backup;
use Lou\Build\Builder;
use Lou\LouException;
use Lou\Support\Fs;
use Lou\Support\Html;
use Lou\View;

require dirname(__DIR__) . '/src/autoload.php';

$tests = [];
$failures = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][$name] = $fn;
}

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function contains(string $haystack, string $needle, string $msg = ''): void
{
    check(str_contains($haystack, $needle), $msg ?: "Expected to find: $needle");
}

/** Fresh install root with a copy of the example site. */
function install(): string
{
    $root = sys_get_temp_dir() . '/lou-test-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn () => Fs::removeDir($root));
    Fs::copyDir(dirname(__DIR__) . '/site', "$root/site");
    return $root;
}

function editJson(string $file, callable $fn): void
{
    Fs::writeJson($file, $fn(Fs::readJson($file)));
}

// ------------------------------------------------------------------------- tests

test('builds every page in both languages with pretty URLs', function () {
    $root = install();
    $r = (new Builder(new App($root)))->build();
    check($r['pages'] === 10, "expected 10 pages, got {$r['pages']}");
    foreach (['index.html', 'rolunk/index.html', 'kapcsolat/index.html', 'en/index.html',
              'en/about-us/index.html', 'en/contact/index.html', '404.html', 'en/404.html',
              'sitemap.xml', 'robots.txt', '.htaccess', 'en/.htaccess', 'assets/css/site.css', '_lou/.htaccess'] as $f) {
        check(is_file("$root/public/$f"), "missing public/$f");
    }
});

test('escapes text, sanitises rich text, adds lang + hreflang', function () {
    $root = install();
    (new Builder(new App($root)))->build();
    $en = file_get_contents("$root/public/en/index.html");
    contains($en, '<html lang="en">');
    contains($en, 'Simple, fast &amp; secure');
    contains($en, 'hreflang="hu" href="https://example.com/"');
    contains($en, 'hreflang="x-default"');
    $hu = file_get_contents("$root/public/rolunk/index.html");
    contains($hu, 'Árvíztűrő tükörfúrógép', 'UTF-8 content must survive');
    contains($hu, 'href="/en/about-us/"', 'language switcher links to the same page');
    check(!str_contains($en, 'data-lou-field'), 'live build must not contain editor markers');
});

test('preview builds carry editor markers and noindex', function () {
    $root = install();
    $out = "$root/var/preview";
    (new Builder(new App($root)))->build(View::MODE_PREVIEW, $out);
    contains(file_get_contents("$out/index.html"), 'data-lou-field="hero.title"');
    contains(file_get_contents("$out/robots.txt"), 'Disallow: /');
    contains(file_get_contents("$out/.htaccess"), 'X-Robots-Tag');
    check(!is_file("$root/var/manifest.json"), 'preview must not touch the live manifest');
});

test('html sanitiser strips scripts, handlers and js: links', function () {
    $out = Html::sanitise('<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">x</a><a href="/ok" target="_blank">ok</a><img src=x onerror=alert(1)></p>');
    check(!str_contains($out, 'script') && !str_contains($out, 'onclick') && !str_contains($out, 'onerror'), "unsafe output: $out");
    check(!str_contains($out, 'javascript:'), "js link kept: $out");
    contains($out, 'rel="noopener noreferrer"');
    contains(Html::sanitise('<p>Árvíz</p>'), 'Árvíz');
});

test('renaming a slug creates a redirect, renaming back removes it', function () {
    $root = install();
    $build = fn () => (new Builder(new App($root)))->build();
    $build();
    editJson("$root/site/content/pages/about/hu.json", fn ($d) => ['slug' => 'cegunkrol'] + $d);
    $r = $build();
    check(count($r['redirects']['moved']) === 1, 'one move expected');
    check(is_file("$root/public/cegunkrol/index.html"), 'new URL built');
    $ht = file_get_contents("$root/public/.htaccess");
    contains($ht, 'RedirectMatch 301 "^/rolunk/?$" "/cegunkrol/"');
    contains(file_get_contents("$root/public/rolunk/index.html"), 'http-equiv="refresh"', 'static fallback for non-Apache hosts');
    $entries = Fs::readJson("$root/site/redirects.json");
    check($entries[0]['to'] === ['page' => 'about', 'lang' => 'hu'], 'redirect targets the page id');

    // rename again: the old redirect follows the page (no chains)
    editJson("$root/site/content/pages/about/hu.json", fn ($d) => ['slug' => 'rolunk-uj'] + $d);
    $build();
    $ht = file_get_contents("$root/public/.htaccess");
    contains($ht, '"^/rolunk/?$" "/rolunk-uj/"');
    contains($ht, '"^/cegunkrol/?$" "/rolunk-uj/"');

    // back to the original: that redirect must disappear
    editJson("$root/site/content/pages/about/hu.json", fn ($d) => ['slug' => 'rolunk'] + $d);
    $build();
    check(!str_contains(file_get_contents("$root/public/.htaccess"), '"^/rolunk/?$"'), 'redirect from a live URL must be removed');
});

test('moving a page under a parent redirects its old URL', function () {
    $root = install();
    $build = fn () => (new Builder(new App($root)))->build();
    $build();
    editJson("$root/site/content/pages/contact/en.json", fn ($d) => ['parent' => 'about'] + $d);
    $build();
    contains(file_get_contents("$root/public/.htaccess"), '"^/en/contact/?$" "/en/about-us/contact/"');
});

test('deleting a page redirects to home and warns', function () {
    $root = install();
    $build = fn () => (new Builder(new App($root)))->build();
    $build();
    Fs::removeDir("$root/site/content/pages/services");
    file_put_contents("$root/site/menu.json", '["home", "about", "contact"]');
    $r = $build();
    check(count($r['redirects']['gone']) === 2, 'two removed URLs expected');
    check((bool) array_filter($r['warnings'], fn ($w) => str_contains($w, "Removed page 'services'")), 'warning expected');
    contains(file_get_contents("$root/public/.htaccess"), '"^/en/services/?$" "/en/"');
});

test('invalid content fails the build and leaves the live site untouched', function () {
    $root = install();
    (new Builder(new App($root)))->build();
    $before = file_get_contents("$root/public/index.html");
    file_put_contents("$root/site/menu.json", '["home", "nope"]');
    try {
        (new Builder(new App($root)))->build();
        check(false, 'build should have failed');
    } catch (LouException $e) {
        contains($e->getMessage(), "unknown page 'nope'");
    }
    check(file_get_contents("$root/public/index.html") === $before, 'live site changed');
    check(glob("$root/.lou-tmp-*") === [], 'temp dir left behind');
});

test('unknown page id in a template link fails the build', function () {
    $root = install();
    file_put_contents("$root/site/templates/page.php", '<?php $v->layout("layout") ?><a href="<?= $v->url("missing") ?>">x</a>');
    try {
        (new Builder(new App($root)))->build();
        check(false, 'build should have failed');
    } catch (LouException $e) {
        contains($e->getMessage(), "unknown page 'missing'");
    }
});

test('previous builds are archived for rollback', function () {
    $root = install();
    $b = new Builder(new App($root));
    $b->build();
    $b->build();
    check(count(glob("$root/var/builds/before-*") ?: []) === 1, 'one archived build expected');
});

test('backup + restore round-trips content, UTF-8 names and the database', function () {
    $root = install();
    $app = new App($root);
    file_put_contents("$root/site/assets/img/árvíztűrő tükör.txt", 'ő');
    Fs::ensureDir("$root/var");
    $pdo = new PDO("sqlite:$root/var/lou.sqlite");
    $pdo->exec('CREATE TABLE t (v TEXT); INSERT INTO t VALUES (\'eredeti\')');
    $pdo = null;

    $file = (new Backup($app))->create();
    check(is_file($file), 'backup file missing');

    // damage things
    editJson("$root/site/content/pages/about/hu.json", fn ($d) => ['title' => 'ELRONTVA'] + $d);
    unlink("$root/site/assets/img/árvíztűrő tükör.txt");
    (new PDO("sqlite:$root/var/lou.sqlite"))->exec("UPDATE t SET v = 'rossz'");

    $safety = (new Backup($app))->restore($file);
    check(is_file($safety), 'safety backup missing');
    check(Fs::readJson("$root/site/content/pages/about/hu.json")['title'] === 'Rólunk', 'content not restored');
    check(is_file("$root/site/assets/img/árvíztűrő tükör.txt"), 'UTF-8 file name not restored');
    $v = (new PDO("sqlite:$root/var/lou.sqlite"))->query('SELECT v FROM t')->fetchColumn();
    check($v === 'eredeti', "database not restored ($v)");
});

test('encrypted backups need the password and include secrets', function () {
    $root = install();
    $app = new App($root);
    file_put_contents("$root/.env.php", "<?php return ['smtp_pass' => 'titok'];");
    $file = (new Backup($app))->create(null, 'jelszó');
    $zip = new ZipArchive();
    $zip->open($file);
    check($zip->locateName('.env.php') !== false, '.env.php should be included');
    check($zip->statName('site/config.php')['encryption_method'] === ZipArchive::EM_AES_256, 'not encrypted');
    $zip->close();
    try {
        (new Backup($app))->restore($file);
        check(false, 'restore without password should fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'password');
    }
    try {
        (new Backup($app))->restore($file, 'wrong');
        check(false, 'restore with wrong password should fail');
    } catch (LouException) {
    }
    (new Backup($app))->restore($file, 'jelszó');

    $plain = (new Backup($app))->create();
    $zip->open($plain);
    check($zip->locateName('.env.php') === false, 'secrets must be excluded without a password');
    $zip->close();
});

test('tampered backups are rejected', function () {
    $root = install();
    $app = new App($root);
    $file = (new Backup($app))->create();
    $zip = new ZipArchive();
    $zip->open($file);
    $zip->addFromString('site/config.php', '<?php return [];');
    $zip->close();
    try {
        (new Backup($app))->restore($file);
        check(false, 'tampered backup should fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'Checksum mismatch');
    }
});

test('slugs and file names are transliterated to ASCII', function () {
    check(Lou\Support\Str::slug('Példa Kft. – Árvíztűrő tükörfúrógép') === 'pelda-kft-arvizturo-tukorfurogep', Lou\Support\Str::slug('Példa Kft. – Árvíztűrő tükörfúrógép'));
    check(Lou\Support\Str::fileName('Árvíz KÉP.JPG') === 'arviz-kep.jpg', Lou\Support\Str::fileName('Árvíz KÉP.JPG'));
});

// ------------------------------------------------------------------------- run

foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  ✗ $name\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\n" . (count($tests) - $failures) . '/' . count($tests) . " passed\n";
exit($failures ? 1 : 0);
