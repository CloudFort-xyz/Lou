<?php

declare(strict_types=1);

use Lou\App;
use Lou\Content\ChangeList;
use Lou\Content\Workspace;
use Lou\Support\Diff;
use Lou\Support\Fs;

/** Change files in a user's draft directly. $changes: path => fn(old contents): new contents (null = delete). */
function draft_edit(App $app, string $owner, array $changes): Workspace
{
    $w = Workspace::draft($app, $owner);
    $w->open();
    foreach ($changes as $path => $fn) {
        $f = $w->siteDir() . "/$path";
        $new = $fn(is_file($f) ? (string) file_get_contents($f) : '');
        $new === null ? unlink($f) : Fs::write($f, $new);
    }
    $w->touch();
    return $w;
}

function json_edit(callable $fn): callable
{
    return fn (string $s) => json_encode($fn(json_decode($s, true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

test('diff: word diff keeps whitespace and round-trips', function () {
    $old = 'Fókuszban az ügyfélelégedettség, a hatékonyság és a minőség.';
    $new = 'Fókuszban az ügyfél elégedettség, a hatékonyság és a minőség.';
    $runs = Diff::words($old, $new);
    check($runs === [[' ', 'Fókuszban az '], ['-', 'ügyfélelégedettség, '], ['+', 'ügyfél elégedettség, '], [' ', 'a hatékonyság és a minőség.']], json_encode($runs, JSON_UNESCAPED_UNICODE));
    $join = fn (string $skip) => implode('', array_map(fn ($r) => $r[0] === $skip ? '' : $r[1], $runs));
    check($join('+') === $old && $join('-') === $new, 'joining gives both texts back');
    check(Diff::words('', 'új') === [['+', 'új']] && Diff::words('a b', 'a b') === [[' ', 'a b']], 'edge cases');
});

test('review: changes are described in plain words, with field changes and a short word diff', function () {
    $long = str_repeat('Több mint húsz éve dolgozunk. ', 12);
    $item = ChangeList::item('content/pages/about/hu.json', 'changed',
        json_encode(['slug' => 'rolunk', 'title' => 'Rólunk', 'fields' => ['body' => "<p>{$long}Fókuszban az ügyfélelégedettség.</p>", 'team' => [['name' => 'Kása MSc/MBA']]]]),
        json_encode(['slug' => 'cegunkrol', 'title' => 'Cégünkről', 'fields' => ['body' => "<p>{$long}Fókuszban az ügyfél elégedettség.</p>", 'team' => [['name' => 'Kása MSc MBA']]]]));
    check($item['group'] === 'pages' && $item['label'] === 'Cégünkről (Hungarian)' && $item['page'] === ['id' => 'about', 'lang' => 'hu'], json_encode($item, JSON_UNESCAPED_UNICODE));
    $names = array_column($item['fields'], 'name');
    check($names === ['Web address', 'Title', 'Body', 'Team › item 1 › name'], implode(' | ', $names));
    $body = $item['fields'][2]['diff'];
    check($body[0][0] === ' ' && str_starts_with($body[0][1], '…') && mb_strlen($body[0][1]) < 80, 'long unchanged start is cut: ' . $body[0][1]);
    check(in_array(['-', 'ügyfélelégedettség.'], $body, true) && in_array(['+', 'ügyfél elégedettség.'], $body, true), json_encode($body, JSON_UNESCAPED_UNICODE));
    check(!str_contains(json_encode($item['fields']), '<p>'), 'tags are stripped');

    $markup = ChangeList::item('content/pages/about/en.json', 'changed', '{"fields":{"body":"<p>Hi</p>"}}', "{\n  \"fields\": {\"body\": \"<p>Hi</p>\"}\n}");
    check($markup['fields'] === [], 'reformatting alone is no change');

    $css = ChangeList::item('assets/css/site.css', 'changed', "a{}\n", "a{}\nb{}\n", "@@ -1 +1 @@\n a{}\n+b{}\n");
    check($css['group'] === 'design' && $css['label'] === 'Stylesheet: site.css' && $css['lines'] === [1, 0], json_encode($css));
    foreach (['templates/layout.php' => 'Template: layout (every page)', 'templates/partials/footer.php' => 'Template part: footer',
              'content/strings/en.json' => 'Buttons, labels and messages (English)', 'menu.json' => 'Menu', 'assets/img/logo.svg' => 'Image: logo.svg'] as $path => $label) {
        check(ChangeList::label($path) === $label, "$path: " . ChangeList::label($path));
    }
    check(ChangeList::group('assets/img/logo.svg') === 'images' && ChangeList::group('config.php') === 'menu', 'groups');
});

test('review: submitting builds the staging preview; Review shows plain changes, Edit and Undo, diffs under Technical details', function () {
    [$admin, , $app] = admin_setup();
    $html = ['headers' => ['accept' => 'text/html']];
    draft_edit($app, 'user:bence', [
        'content/pages/about/en.json' => json_edit(function ($d) { $d['title'] = 'Who we are'; return $d; }),
        'assets/css/site.css'         => fn ($s) => $s . "body{background:#fdf6e3}\n",
    ]);
    $page = $admin->handle(admin_req('GET', 'review', [], $html))->body;
    contains($page, '2 changes only you can see');
    contains($page, 'Who we are (English)');
    contains($page, '<del>About us</del> → <ins>Who we are</ins>', 'old → new for a short value');
    contains($page, '?r=edit&amp;page=about&amp;lang=en', 'Edit opens the page in the editor');
    check(substr_count($page, '?r=edit&amp;') === 1, 'no Edit link for the stylesheet');
    contains($page, '<summary>Technical details</summary>');

    $r = $admin->handle(admin_req('POST', 'submit', ['summary' => 'Warmer'], $html));
    check($r->status === 303, 'submit redirects');
    $s = Workspace::staging($app);
    $preview = $s->lastPreview();
    check($preview !== null && (int) $preview['rev'] === (int) $s->meta()['rev'], 'the preview was built on submit and is current');
    check(is_file($app->outputDir() . parse_url($preview['url'], PHP_URL_PATH) . 'en/about-us/index.html'), 'preview files exist');
    $page = $admin->handle(admin_req('GET', 'review', [], $html))->body;
    contains($page, 'Submitted 2 changes to staging. The preview is ready.');
    contains($page, 'Open preview');
    contains($page, '2 changes waiting for review');
    contains($page, 'Publish 2 changes');
    contains($page, 'by bence');
    contains($page, 'r=staging-revert');
    contains($page, '<code>assets/css/site.css</code>', 'paths only in the technical details');
});

test('review: Undo takes one change off staging; undoing the last one closes staging', function () {
    [$admin, , $app] = admin_setup();
    $html = ['headers' => ['accept' => 'text/html']];
    draft_edit($app, 'user:bence', [
        'content/pages/about/en.json' => json_edit(function ($d) { $d['title'] = 'Who we are'; return $d; }),
        'assets/css/site.css'         => fn ($s) => $s . "body{background:#fdf6e3}\n",
    ]);
    $admin->handle(admin_req('POST', 'submit', ['summary' => 'two things']));
    $rev = Workspace::staging($app)->meta()['rev'];

    $r = $admin->handle(admin_req('POST', 'staging-revert', ['files' => ['assets/css/site.css']], $html));
    check($r->status === 303, 'redirects to Review');
    $s = Workspace::staging($app);
    check($s->changedFiles() === ['content/pages/about/en.json'], 'only the page is left: ' . implode(',', $s->changedFiles()));
    check($s->lastPreview()['rev'] === $s->meta()['rev'] && $s->meta()['rev'] > $rev, 'preview rebuilt for what is left');
    check(!str_contains((string) file_get_contents($app->siteDir . '/assets/css/site.css'), 'fdf6e3'), 'live untouched');
    contains($admin->handle(admin_req('GET', 'review', [], $html))->body, 'Took 1 change off staging.');

    check($admin->handle(admin_req('POST', 'staging-revert', ['files' => ['menu.json']]))->status === 422, 'only staged files');
    $admin->handle(admin_req('POST', 'staging-revert', ['files' => ['content/pages/about/en.json']], $html));
    check(!Workspace::staging($app)->exists(), 'staging closed when nothing is left');

    [$editor] = admin_setup('editor');
    check($editor->handle(admin_req('POST', 'staging-revert', ['files' => ['x']]))->status !== 403, 'editors may undo staged changes');
});

test('review: a design zip goes straight to staging with a preview, unless the draft holds other edits', function () {
    [$admin, , $app] = admin_setup();
    $html = ['headers' => ['accept' => 'text/html']];
    $zip = client_zip($app->siteDir, ['assets/css/site.css' => "body{color:#c9a227}\n"]);
    $upload = fn () => $admin->handle(admin_req('POST', 'design-upload', ['review' => '1'], $html,
        ['site' => ['tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'name' => '../kner-main.zip']]));

    $r = $upload();
    check($r->status === 303 && str_ends_with($r->headers['Location'], '?r=review'), 'redirects to Review: ' . strip_tags($r->body));
    $s = Workspace::staging($app);
    check($s->changedFiles() === ['assets/css/site.css'] && !Workspace::draft($app, 'user:bence')->exists(), 'on staging, draft empty');
    check($s->meta()['submissions'][0]['summary'] === 'Site design from kner-main.zip', $s->meta()['submissions'][0]['summary']);
    check($s->lastPreview() !== null, 'preview built');
    contains($admin->handle(admin_req('GET', 'review', [], $html))->body, 'The new design from kner-main.zip is on staging (1 change). Check the preview, then publish.');

    // with other edits in the draft, the import waits there
    $admin->handle(admin_req('POST', 'reject'));
    draft_edit($app, 'user:bence', ['content/pages/about/en.json' => json_edit(function ($d) { $d['title'] = 'X'; return $d; })]);
    $r = $upload();
    check($r->status === 200, 'result page');
    contains($r->body, 'Your draft already had other changes');
    check(!Workspace::staging($app)->exists(), 'nothing staged');
    check(count(Workspace::draft($app, 'user:bence')->changedFiles()) === 2, 'both in the draft');

    // unchecked: draft only (the old behaviour)
    Workspace::draft($app, 'user:bence')->discard();
    $r = $admin->handle(admin_req('POST', 'design-upload', [], $html, ['site' => ['tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'name' => 'z.zip']]));
    contains($r->body, 'Submit it under Review when you are ready.');
    check(!Workspace::staging($app)->exists(), 'not submitted');
});

test('review: an assistant submitting gets the preview link back', function () {
    [$mcp, $token, $app] = bridge_setup();
    $css = call_tool($mcp, $token, 'read_file', ['path' => 'assets/css/site.css'])['content'][0]['text'];
    call_tool($mcp, $token, 'write_file', ['path' => 'assets/css/site.css', 'content' => $css . "\nb{}\n"]);
    $r = json_decode(call_tool($mcp, $token, 'submit_for_review', ['summary' => 'bold'])['content'][0]['text'], true);
    check(str_contains((string) $r['preview_url'], '/_lou/p/'), json_encode($r));
    check(Workspace::staging($app)->lastPreview() !== null, 'Review has it too');
});
