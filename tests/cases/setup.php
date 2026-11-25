<?php

declare(strict_types=1);

use Lou\App;
use Lou\Build\Builder;
use Lou\LouException;
use Lou\Setup\Deployer;
use Lou\Setup\Requirements;
use Lou\Support\Fs;

function check_item(array $results, string $label): ?array
{
    foreach ($results as $i) {
        if ($i['label'] === $label) {
            return $i;
        }
    }
    return null;
}

function temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/lou-test-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn () => Fs::removeDir($dir));
    Fs::ensureDir($dir);
    return $dir;
}

test('check: missing required extension or old PHP fails, missing recommended ones only warn', function () {
    $root = install();
    $all = (new Requirements($root, fn () => true, fn () => true, 80300, '256M'))->php();
    check(Requirements::passed($all), 'everything present should pass');

    $noSqlite = (new Requirements($root, fn ($e) => $e !== 'pdo_sqlite', fn () => true, 80300))->php();
    check(!Requirements::passed($noSqlite), 'missing pdo_sqlite should fail');
    check(check_item($noSqlite, 'ext-pdo_sqlite')['level'] === Requirements::FAIL, 'pdo_sqlite marked FAIL');

    $old = (new Requirements($root, fn () => true, fn () => true, 80227))->php();
    check(check_item($old, 'PHP 8.2.27')['level'] === Requirements::FAIL, 'PHP 8.2 should fail');

    $soft = (new Requirements($root, fn ($e) => !in_array($e, ['intl', 'exif'], true), fn ($f) => $f !== 'imagewebp', 80300, '64M'))->php();
    check(Requirements::passed($soft), 'intl/exif/WebP/memory are recommendations');
    check(check_item($soft, 'GD WebP support')['level'] === Requirements::WARN, 'no WebP warns');
    check(check_item($soft, 'ext-intl')['level'] === Requirements::WARN, 'no intl warns');
    check(check_item($soft, 'memory_limit')['level'] === Requirements::WARN, 'low memory warns');
});

test('check: example base_url fails; install warnings for missing .env.php and log mail; symlinked output fails', function () {
    $root = install();
    $r = (new Requirements($root, fn () => true, fn () => true))->run();
    check(check_item($r, 'base_url')['level'] === Requirements::FAIL, 'the example base_url fails loudly');
    foreach (['.env.php', 'mail'] as $label) {
        check(check_item($r, $label)['level'] === Requirements::WARN, "$label should warn");
    }
    check(check_item($r, 'output folder')['level'] === Requirements::OK, 'output folder ok');
    Fs::writeJson("$root/var/settings.json", ['base_url' => 'https://lou.cloudfort.xyz']);
    $r = (new Requirements($root, fn () => true, fn () => true))->run();
    check(Requirements::passed($r) && check_item($r, 'base_url') === null, 'a base_url set in the admin passes');
    Fs::writeJson("$root/var/settings.json", ['base_url' => 'https://lou.cloudfort.xyz', 'mail' => ['transport' => 'smtp', 'from' => 'web@pelda.hu']]);
    $r = (new Requirements($root, fn () => true, fn () => true))->run();
    check(check_item($r, 'mail')['level'] === Requirements::FAIL, 'SMTP without a server fails');
    Lou\Env::storeAdminSecret(new Lou\App($root), 'smtp', ['host' => 'mail.pelda.hu', 'port' => 587]);
    $r = (new Requirements($root, fn () => true, fn () => true))->run();
    check(check_item($r, 'mail') === null, 'an SMTP server set in the admin passes');

    $elsewhere = temp_dir();
    symlink($elsewhere, "$root/public");
    $r = (new Requirements($root, fn () => true, fn () => true))->run();
    check(check_item($r, 'output folder')['level'] === Requirements::FAIL, 'symlinked output dir should fail');
});

test('deploy: copies code, seeds site/ once, keeps site/, var/ and .env.php on later deploys', function () {
    $repo = dirname(__DIR__, 2);
    $target = temp_dir() . '/lou';
    $r = (new Deployer($repo))->deploy($target);
    check($r['seeded'], 'first deploy seeds site/');
    foreach (['lou', 'src/App.php', 'stubs/_lou/submit.php', 'vendor-bundled', '.env.example.php', 'site/config.php'] as $p) {
        check(file_exists("$target/$p"), "missing $p");
    }
    check(is_executable("$target/lou"), 'lou is executable');
    (new Builder(new App($target)))->build();
    check(is_file("$target/public/index.html"), 'install builds');

    // server-side state that a deploy must not touch
    Fs::write("$target/site/content/pages/home/hu.json.marker", 'edited on server');
    Fs::write("$target/.env.php", "<?php return [];\n");
    Fs::write("$target/var/keep.txt", 'runtime');
    Fs::write("$target/src/Stale.php", 'removed upstream');

    $r = (new Deployer($repo))->deploy($target);
    check(!$r['seeded'], 'second deploy keeps site/');
    check(is_file("$target/site/content/pages/home/hu.json.marker"), 'site/ untouched');
    check(is_file("$target/.env.php") && is_file("$target/var/keep.txt"), '.env.php and var/ untouched');
    check(is_file("$target/public/index.html"), 'live build untouched');
    check(!is_file("$target/src/Stale.php"), 'code folders are replaced whole');
    check(glob("$target/.lou-deploy-*") === [] && glob("$target/*.lou-old") === [], 'no leftovers');
});

test('deploy --site: seeds from a client site once, then updates templates/assets except server-edited files', function () {
    $repo = dirname(__DIR__, 2);
    $client = temp_dir();
    Fs::copyDir("$repo/site", "$client/site");
    Fs::write("$client/site/templates/client-marker.php", 'client');
    $target = temp_dir() . '/kner';
    $r = (new Deployer($repo, "$client/site"))->deploy($target);
    check($r['seeded'], 'first deploy seeds site/');
    check(is_file("$target/site/templates/client-marker.php"), 'seeded from the client site, not the example');
    check(is_file("$target/src/App.php"), 'code still comes from the Lou checkout');

    // server-side edits: content, uploads and server-edited design files must survive
    Fs::write("$target/site/content/pages/home/hu.json.marker", 'edited on server');
    Fs::write("$target/site/assets/uploads/2026/photo.webp", 'uploaded');
    Fs::write("$target/site/templates/assistant-new.php", 'made on the server');
    Fs::write("$target/site/templates/page.php", 'edited on the server');
    Fs::write("$client/site/templates/page.php", 'edited in the repo');
    Fs::write("$client/site/templates/later.php", 'later');
    Fs::write("$client/site/assets/css/site.css", 'body{}');
    unlink("$client/site/templates/client-marker.php");
    Fs::write("$client/site/content/pages/home/hu.json.repo", 'repo content');
    $r = (new Deployer($repo, "$client/site"))->deploy($target);
    $d = $r['design'];
    check(!$r['seeded'], 'later deploys do not seed');
    check($d['updated'] === ['assets/css/site.css', 'templates/later.php'], 'updated: ' . implode(',', $d['updated']));
    check($d['deleted'] === ['templates/client-marker.php'], 'deleted: ' . implode(',', $d['deleted']));
    check($d['skipped'] === ['templates/page.php'], 'skipped: ' . implode(',', $d['skipped']));
    check(file_get_contents("$target/site/templates/page.php") === 'edited on the server', 'server edit not overwritten');
    check(is_file("$target/site/templates/assistant-new.php"), 'server-only template kept');
    check(!is_file("$target/site/templates/client-marker.php"), 'template removed in the repo is removed');
    check(file_get_contents("$target/site/assets/css/site.css") === 'body{}', 'css deployed');
    check(is_file("$target/site/assets/uploads/2026/photo.webp"), 'editor uploads kept');
    check(is_file("$target/site/content/pages/home/hu.json.marker"), 'server content kept');
    check(!is_file("$target/site/content/pages/home/hu.json.repo"), 'repo content not deployed');

    // the conflict stays reported until resolved; committing the server's version resolves it
    $r = (new Deployer($repo, "$client/site"))->deploy($target);
    check($r['design']['skipped'] === ['templates/page.php'] && $r['design']['updated'] === [], 'still skipped, nothing else');
    Fs::write("$client/site/templates/page.php", 'edited on the server');
    $r = (new Deployer($repo, "$client/site"))->deploy($target);
    check($r['design']['skipped'] === [], 'resolved');
    Fs::write("$client/site/templates/page.php", 'v2');
    $r = (new Deployer($repo, "$client/site"))->deploy($target);
    check($r['design']['updated'] === ['templates/page.php'], 'repo updates flow again after resolving');

    $plain = (new Deployer($repo))->deploy(temp_dir() . '/plain');
    check($plain['design']['updated'] === [] && !is_file(dirname($plain['target']) . '/plain/var/deploy-design.json'), 'without --site nothing is synced');

    try {
        (new Deployer($repo, temp_dir()))->deploy(temp_dir() . '/x');
        check(false, 'a folder without config.php should fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'not a Lou site folder');
    }
});

test('git: finds the client repo, its .git folder and updates submodules from a site/ subfolder', function () {
    $dir = temp_dir();
    $git = function (string $cwd, string ...$args): void {
        $cmd = 'git -C ' . escapeshellarg($cwd) . ' -c user.name=t -c user.email=t@t -c init.defaultBranch=main '
            . '-c protocol.file.allow=always ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        check($code === 0, "$cmd: " . implode("\n", $out));
    };
    mkdir("$dir/core");
    $git("$dir/core", 'init', '-q');
    file_put_contents("$dir/core/lou", 'x');
    $git("$dir/core", 'add', '.');
    $git("$dir/core", 'commit', '-qm', 'core');
    mkdir("$dir/client/site", 0777, true);
    $git("$dir/client", 'init', '-q');
    file_put_contents("$dir/client/site/config.php", '<?php return [];');
    $git("$dir/client", 'submodule', 'add', '-q', "$dir/core", 'lou');
    $git("$dir/client", 'commit', '-qam', 'client');

    $client = new Lou\Support\Git("$dir/client/site");
    check($client->topLevel() === realpath("$dir/client"), 'top level: ' . $client->topLevel());
    check($client->gitDir() === realpath("$dir/client") . '/.git', 'git dir: ' . $client->gitDir());
    check(Lou\Support\Git::available("$dir/client/lou"), 'a submodule (.git file) counts as a checkout');
    Fs::removeDir("$dir/client/lou");
    mkdir("$dir/client/lou");
    $client->updateSubmodules();
    check(is_file("$dir/client/lou/lou"), 'submodule checked out again');
});

test('deploy: refuses relative targets and the checkout itself', function () {
    $repo = dirname(__DIR__, 2);
    foreach (['relative/lou', $repo] as $bad) {
        try {
            (new Deployer($repo))->deploy($bad);
            check(false, "deploy to $bad should fail");
        } catch (LouException) {
        }
    }
    try {
        (new Deployer(temp_dir()))->deploy(temp_dir() . '/lou');
        check(false, 'non-Lou source should fail');
    } catch (LouException $e) {
        contains($e->getMessage(), 'not a Lou checkout');
    }
});

test('deploy targets: every line of ~/.lou-target, ~ expanded, comments skipped; ~/lou without the file', function () {
    $home = temp_dir();
    check(Deployer::targets($home) === ["$home/lou"], 'default is ~/lou');
    file_put_contents("$home/.lou-target", "/srv/a\n\n# old client\n~/domains/b.hu\n  /srv/c  \n");
    check(Deployer::targets($home) === ['/srv/a', "$home/domains/b.hu", '/srv/c'], implode(',', Deployer::targets($home)));
    file_put_contents("$home/.lou-target", "\n# nothing\n");
    check(Deployer::targets($home) === ["$home/lou"], 'empty file falls back to ~/lou');
});

test('deploy --pull: fast-forwards only when upstream has new commits', function () {
    $dir = temp_dir();
    $git = function (string $cwd, string ...$args): string {
        $cmd = 'git -C ' . escapeshellarg($cwd) . ' -c user.name=t -c user.email=t@t -c init.defaultBranch=main '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $out, $code);
        check($code === 0, "$cmd: " . implode("\n", $out));
        return implode("\n", $out);
    };
    mkdir("$dir/origin");
    $git("$dir/origin", 'init', '-q', '--bare');
    $git($dir, 'clone', '-q', "$dir/origin", 'dev');
    file_put_contents("$dir/dev/a.txt", '1');
    $git("$dir/dev", 'add', '.');
    $git("$dir/dev", 'commit', '-qm', 'one');
    $git("$dir/dev", 'push', '-q', 'origin', 'HEAD:main');
    $git($dir, 'clone', '-q', '-b', 'main', "$dir/origin", 'server');

    $server = new Lou\Support\Git("$dir/server");
    check($server->pullFastForward() === false, 'nothing new');
    file_put_contents("$dir/dev/a.txt", '2');
    $git("$dir/dev", 'commit', '-qam', 'two');
    $git("$dir/dev", 'push', '-q', 'origin', 'HEAD:main');
    check($server->pullFastForward() === true, 'new commit pulled');
    check(file_get_contents("$dir/server/a.txt") === '2', 'working tree updated');
    check($server->pullFastForward() === false, 'nothing new again');
});
