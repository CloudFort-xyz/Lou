<?php

declare(strict_types=1);

use Lou\App;
use Lou\Build\Builder;
use Lou\Content\History;
use Lou\Content\Workspace;
use Lou\Db\Database;
use Lou\Support\Fs;

/** @return array{0: App, 1: Database, 2: Lou\Admin\AdminApp, 3: Lou\Admin\AdminApp} admin "bence" and editor "kliens" */
function workflow_setup(string $config = ''): array
{
    $root = install();
    if ($config !== '') {
        file_put_contents("$root/site/config.php", str_replace("'base_url'", "$config\n    'base_url'", file_get_contents("$root/site/config.php")));
    }
    $app = new App($root);
    (new Builder($app))->build();
    $db = new Database($app->databaseFile());
    return [$app, $db, editor_admin($app, $db), editor_admin($app, $db, 'kliens', 'editor')];
}

function save_title(Lou\Admin\AdminApp $who, string $value, string $page = 'about', string $lang = 'hu'): void
{
    $r = $who->handle(admin_req('POST', 'save', ['page' => $page, 'lang' => $lang, 'field' => 'hero.title', 'type' => 'text', 'value' => $value]));
    check($r->status === 200, $r->body);
}

function staged_title(App $app, string $page = 'about', string $lang = 'hu'): string
{
    return Fs::readJson(Workspace::staging($app)->siteDir() . "/content/pages/$page/$lang.json")['fields']['hero']['title'];
}

test('workflow: drafts are private until submitted; staging collects them; publish applies all', function () {
    [$app, , $bence, $kliens] = workflow_setup();
    save_title($bence, 'Bence címe');
    save_title($kliens, 'Contact (client)', 'contact', 'en');
    check(Fs::readJson(draft_site($app, 'kliens') . '/content/pages/about/hu.json')['fields']['hero']['title'] !== 'Bence címe', 'drafts are private');

    $bence->handle(admin_req('POST', 'submit', ['summary' => 'Új cím']));
    check(staged_title($app) === 'Bence címe', 'on staging');
    check(!Workspace::draft($app, 'user:bence')->exists(), 'submitted draft is emptied');
    // the client's draft now shows the staged title (rebased), keeping their own change
    $kliens->handle(admin_req('GET', 'pages', [], ['headers' => ['accept' => 'text/html']]));
    check(Fs::readJson(draft_site($app, 'kliens') . '/content/pages/about/hu.json')['fields']['hero']['title'] === 'Bence címe', 'draft rebased onto staging');
    $kliens->handle(admin_req('POST', 'submit', ['summary' => 'Contact title']));
    $meta = Workspace::staging($app)->meta();
    check(count($meta['submissions']) === 2, 'two submissions');

    $html = $kliens->handle(admin_req('GET', 'review', [], ['headers' => ['accept' => 'text/html']]))->body;
    contains($html, 'Új cím');
    contains($html, 'Contact title');
    $r = submit_and_publish($kliens, $app);
    check($r->status === 200, strip_tags($r->body));
    contains(file_get_contents($app->outputDir() . '/rolunk/index.html'), 'Bence címe');
    contains(file_get_contents($app->outputDir() . '/en/contact/index.html'), 'Contact (client)');
});

test('workflow: same file submitted by two people is a conflict; take theirs or overwrite', function () {
    [$app, , $bence, $kliens] = workflow_setup();
    save_title($bence, 'Bence');
    save_title($kliens, 'Kliens');
    $bence->handle(admin_req('POST', 'submit'));
    $r = $kliens->handle(admin_req('POST', 'submit', ['summary' => 'mine'], ['headers' => ['accept' => 'text/html']]));
    check($r->status === 409, 'conflict expected, got ' . $r->status);
    contains($r->body, 'content/pages/about/hu.json');
    check(staged_title($app) === 'Bence', 'staging unchanged');

    $r = $kliens->handle(admin_req('POST', 'submit', ['force' => '1', 'summary' => 'mine']));
    check($r->status === 303 && staged_title($app) === 'Kliens', 'force overwrites');

    save_title($bence, 'Bence again');
    $bence->handle(admin_req('POST', 'revert', ['files' => ['content/pages/about/hu.json']]));
    check(!Workspace::draft($app, 'user:bence')->exists(), 'taking their version empties the draft');
});

test('workflow: admin edits to other files and live redirects survive; reject keeps drafts', function () {
    [$app, , $bence, $kliens] = workflow_setup();
    $s = Workspace::staging($app);
    $s->open();
    Fs::writeJson($s->siteDir() . '/redirects.json', [['from' => '/kampany/', 'to' => '/en/', 'status' => 301]]);
    $s->touch();
    // meanwhile a live build added an automatic redirect to site/
    Fs::writeJson($app->siteDir . '/redirects.json', [['from' => '/auto/', 'to' => ['page' => 'home', 'lang' => 'hu'], 'status' => 301]]);
    check($s->conflicts() === [], 'redirects.json never conflicts');
    $s->apply();
    check(array_column(Fs::readJson($app->siteDir . '/redirects.json'), 'from') === ['/auto/', '/kampany/'], 'redirects merged');

    save_title($kliens, 'Kliens');
    save_title($bence, 'Bence', 'contact', 'en');
    $bence->handle(admin_req('POST', 'submit'));
    $kliens->handle(admin_req('POST', 'reject', [], ['headers' => ['accept' => 'text/html']]));
    check(!Workspace::staging($app)->exists(), 'rejected');
    check(Workspace::draft($app, 'user:kliens')->hasChanges(), 'other drafts are kept');
});

test('workflow: history keeps old and new versions of every published file, templates included', function () {
    [$app, , $bence] = workflow_setup();
    save_title($bence, 'Első');
    submit_and_publish($bence, $app, 'First title');
    // an assistant-style template change
    $d = Workspace::draft($app, 'llm:Claude');
    $tpl = $d->read('templates/page.php');
    $d->write('templates/page.php', $tpl . "\n<!-- v2 -->\n");
    $d->apply();
    Workspace::staging($app)->addSubmission('llm:Claude', 'Template tweak', ['templates/page.php']);
    submit_and_publish($bence, $app);

    $h = new History($app);
    $all = $h->all();
    check(count($all) === 2, 'two entries');
    check($all[0]['files'] === ['templates/page.php' => 'modified'] && $all[0]['submissions'][0]['by'] === 'llm:Claude', 'template change recorded');
    check(count($h->all('content/pages/about/hu.json')) === 1, 'per-file history');
    contains($h->diffs($all[0]['id'])['templates/page.php'], '+<!-- v2 -->');

    $html = $bence->handle(admin_req('GET', 'history', [], ['headers' => ['accept' => 'text/html']]))->body;
    contains($html, 'Template tweak');
    contains($html, 'First title');
    check($bence->handle(admin_req('GET', 'history-entry', [], ['query' => ['id' => $all[0]['id']], 'headers' => ['accept' => 'text/html']]))->status === 200, 'entry page');
    check($bence->handle(admin_req('GET', 'history-entry', [], ['query' => ['id' => '../x']]))->status === 422, 'bad id');

    // undo the template change: it goes into my draft, then live the usual way
    $bence->handle(admin_req('POST', 'history-restore', ['id' => $all[0]['id'], 'side' => 'before']));
    check(Workspace::draft($app, 'user:bence')->changedFiles() === ['templates/page.php'], 'undo lands in the draft');
    submit_and_publish($bence, $app, 'Undo template tweak');
    check(file_get_contents($app->siteDir . '/templates/page.php') === $tpl, 'template restored');
    check(count($h->all()) === 3, 'the undo is history too');
});

test('workflow: history limits are configurable and it can be turned off', function () {
    [$app, , $bence] = workflow_setup("'history' => ['keep' => 2],");
    foreach (['A', 'B', 'C'] as $t) {
        save_title($bence, $t);
        submit_and_publish($bence, $app);
    }
    $h = new History($app);
    check(count($h->all()) === 2, 'only the last 2 kept, got ' . count($h->all()));
    check(str_contains((string) $h->version($h->all()[0]['id'], 'after', 'content/pages/about/hu.json'), '"C"'), 'newest kept');

    [$app2, , $bence2] = workflow_setup("'history' => false,");
    save_title($bence2, 'X');
    submit_and_publish($bence2, $app2);
    check((new History($app2))->all() === [], 'disabled');
});

test('workflow: published changes are committed to Git when enabled', function () {
    if (trim((string) shell_exec('command -v git')) === '') {
        return; // no git on this machine
    }
    [$app, , $bence] = workflow_setup("'git' => ['commit' => true],");
    $root = $app->root;
    $git = fn (string ...$a) => trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $a)) . ' 2>&1'));
    $git('init', '-q');
    $git('-c', 'user.name=t', '-c', 'user.email=t@t', 'add', 'site');
    $git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'init');
    save_title($bence, 'Gitben');
    $r = submit_and_publish($bence, $app, 'Darker text');
    contains($r->body, 'Committed to Git');
    contains($git('log', '-1', '--format=%s|%an'), 'Darker text|bence');
    check($git('status', '--porcelain', '--', 'site') === '', 'site/ is clean after the commit');
});
