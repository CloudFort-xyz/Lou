<?php

declare(strict_types=1);

use Lou\Admin\I18n;
use Lou\Admin\Prefs;
use Lou\Admin\Users;
use Lou\App;
use Lou\Support\Fs;

/** Every English text the admin translates: [text => file it is used in]. */
function i18n_keys(): array
{
    $src = dirname(__DIR__, 2) . '/src/Admin';
    $keys = [];
    foreach ([...glob("$src/views/*.php"), "$src/AdminApp.php"] as $f) {
        preg_match_all('/(?:\$t(?:->plain)?|->tr)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', (string) file_get_contents($f), $m);
        foreach ($m[1] as $lit) {
            $keys[stripcslashes(substr($lit, 1, -1))] = basename($f);
        }
    }
    preg_match_all('/\bt\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', (string) file_get_contents(dirname(__DIR__, 2) . '/stubs/_lou/admin.js'), $m);
    foreach ($m[1] as $lit) {
        $keys[stripcslashes($lit)] = 'admin.js';
    }
    return $keys;
}

/** Placeholders and tags of a message, to compare a translation with its English key. */
function i18n_shape(string $s): array
{
    preg_match_all('/\{!?[a-z_]+\}|<\/?[a-z]+>/', $s, $m);
    sort($m[0]);
    return array_values(array_unique($m[0]));
}

test('i18n: every admin text has a Hungarian translation with the same placeholders and tags', function () {
    $hu = require dirname(__DIR__, 2) . '/src/Admin/lang/hu.php';
    $keys = i18n_keys();
    check(count($keys) > 300, 'the extractor found only ' . count($keys) . ' texts');
    $missing = array_diff_key($keys, $hu);
    check(!$missing, "missing Hungarian translations:\n  " . implode("\n  ", array_map(fn ($k, $f) => "$f: $k", array_keys($missing), $missing)));
    foreach ($hu as $en => $text) {
        check(i18n_shape($en) === i18n_shape($text), "placeholders or tags differ: '$en' → '$text'");
        check(!str_contains($en . $text, '"'), "no straight double quotes (they would end an HTML attribute): $en");
        check(substr_count($en, '|') === 0 || str_contains($en, '{n}'), "a one|many text needs {n}: $en");
        check(trim($text) !== '', "empty translation: $en");
    }
});

test('i18n: admin.js gets exactly the strings it uses', function () {
    $js = array_keys(array_filter(i18n_keys(), fn ($f) => $f === 'admin.js'));
    sort($js);
    $list = I18n::JS;
    sort($list);
    check($js === $list, 'I18n::JS and admin.js disagree: ' . implode(', ', array_merge(array_diff($js, $list), array_diff($list, $js))));
    check(I18n::load('en')->js() === [], 'English needs no strings');
    check(count(I18n::load('hu')->js()) === count(I18n::JS), 'Hungarian has them all');
});

test('i18n: translating escapes values, picks plural forms and falls back to English', function () {
    $hu = I18n::load('hu');
    check($hu('Log out') === 'Kijelentkezés', $hu('Log out'));
    check($hu('Not a known text') === 'Not a known text', 'unknown texts stay English');
    check($hu('Copy {what}', ['what' => '<b>']) === 'Másolás: &lt;b&gt;', 'values are escaped');
    check($hu->plain('Copy {what}', ['what' => '<b>']) === 'Másolás: <b>', 'plain() leaves escaping to the caller');
    check($hu('Published {!time}', ['time' => '<time>x</time>']) === 'Közzétéve: <time>x</time>', '{!name} is inserted as HTML');
    $en = I18n::load('en');
    check($en('{n} file|{n} files', ['n' => 1]) === '1 file' && $en('{n} file|{n} files', ['n' => 3]) === '3 files', 'English plurals');
    check($hu('{n} file|{n} files', ['n' => 3]) === '3 fájl', 'Hungarian has one form');
    check(I18n::load('xx')->lang === 'en', 'unknown languages fall back to English');
    check(I18n::negotiate('de-DE,de;q=0.9,hu;q=0.8,en;q=0.5') === 'hu', 'best supported language');
    check(I18n::negotiate('en-GB,en;q=0.9') === 'en' && I18n::negotiate('fr') === null, 'English, or none');
});

test('i18n: dates follow the chosen format and time zone', function () {
    $at = '2026-11-25T19:46:38Z';
    check((new Prefs())->stamp($at, false, 'hu') === '<time datetime="2026-11-25T19:46:38Z">2026. 11. 25. 19:46 UTC</time>', 'Hungarian format by default for hu');
    check((new Prefs(dateFormat: 'us'))->stamp($at, false, 'hu') === '<time datetime="2026-11-25T19:46:38Z">11/25/2026 7:46 PM UTC</time>', 'a chosen format wins');
    $fixed = (new Prefs(timezone: 'Europe/Budapest'))->stamp($at, false, 'en');
    check($fixed === '<time datetime="2026-11-25T19:46:38Z" data-fixed title="2026-11-25 19:46 UTC">2026-11-25 20:46</time>', $fixed);
    check((new Prefs(timezone: 'Europe/Budapest', dateFormat: 'eu'))->stamp($at, true, 'en') === '<time datetime="2026-11-25T19:46:38Z" data-date data-fixed title="2026-11-25 19:46 UTC">25.11.2026</time>', 'date only');
    $p = Prefs::fromArray(['lang' => 'xx', 'timezone' => 'Mars/Base', 'date_format' => 'nope', 'edit_lang' => '../x', 'start_page' => 'builds']);
    check($p->toArray() === [], 'bad stored values fall back to the defaults');
});

test('i18n: users keep their preferences when re-added, and can change their password', function () {
    $root = install();
    $users = new Users((new App($root))->varDir . '/users.json');
    $users->add('anna', 'first password 1', 'editor');
    $users->setPrefs('Anna', new Prefs(lang: 'hu', timezone: 'Europe/Budapest'));
    $users->add('anna', 'second password 2', 'admin');
    check($users->prefs('anna')->lang === 'hu' && $users->all()['anna']['role'] === 'admin', 'prefs survive a password reset');
    try {
        $users->changePassword('anna', 'wrong one!!', 'third password 3');
        check(false, 'a wrong current password must fail');
    } catch (Lou\LouException) {
    }
    $users->changePassword('anna', 'second password 2', 'third password 3');
    check($users->verify('anna', 'third password 3') === 'admin', 'password changed');
    $users->setPrefs('anna', new Prefs());
    check(!isset($users->all()['anna']['prefs']), 'default prefs are not stored');
});

test('admin: profile switches the whole admin to Hungarian for that user only', function () {
    [$admin, $session, $app] = admin_setup();
    $html = ['headers' => ['accept' => 'text/html']];
    $page = $admin->handle(admin_req('GET', 'profile', [], $html));
    check($page->status === 200, 'profile page');
    contains($page->body, '<option value="Europe/Budapest">Europe/Budapest</option>');
    contains($page->body, '<html lang="en">');

    $r = $admin->handle(admin_req('POST', 'profile', ['lang' => 'hu', 'timezone' => 'Europe/Budapest', 'date_format' => '', 'edit_lang' => 'en', 'start_page' => 'review'], $html));
    check($r->status === 303, 'saved');
    $page = $admin->handle(admin_req('GET', 'profile', [], $html))->body;
    contains($page, '<html lang="hu">');
    contains($page, 'A beállításaid elmentve.', 'the notice is in the new language');
    contains($page, '<span>Vezérlőpult</span>', 'menu translated');
    contains($page, 'data-i18n="', 'admin.js strings are handed over');
    contains($page, 'data-date="Y. m. d." data-time="H:i"', 'Hungarian date format for admin.js');
    contains($page, '<option value="Europe/Budapest" selected>');
    $dash = $admin->handle(admin_req('GET', 'dashboard', [], $html))->body;
    contains($dash, 'Legutóbbi beküldések');
    check(!str_contains($dash, 'Latest submissions'), 'no English left on the dashboard');

    $stored = Fs::readJson($app->varDir . '/users.json')['bence']['prefs'];
    check($stored === ['lang' => 'hu', 'timezone' => 'Europe/Budapest', 'edit_lang' => 'en', 'start_page' => 'review'], json_encode($stored));
    check(str_contains((string) $admin->handle(admin_req('GET', 'edit', [], $html))->body, 'data-lang="en"'), 'the editor opens in the chosen content language');

    // the other user still sees English
    $session->set('user', 'kliens');
    $session->set('role', 'editor');
    contains($admin->handle(admin_req('GET', 'dashboard', [], $html))->body, 'Latest submissions');

    check($admin->handle(admin_req('POST', 'profile', ['timezone' => 'Mars/Base'], $html))->status === 200
        && !str_contains(json_encode(Fs::readJson($app->varDir . '/users.json')['kliens']), 'Mars'), 'unknown time zones are refused');
});

test('admin: login opens the start page, and the login page follows the site or browser language', function () {
    [$admin, $session, $app] = admin_setup(loggedIn: false);
    (new Users($app->varDir . '/users.json'))->setPrefs('bence', new Prefs(startPage: 'pages'));
    $r = $admin->handle(admin_req('POST', 'login', ['user' => 'bence', 'password' => 'correct horse battery']));
    check($r->status === 303 && str_ends_with((string) ($r->headers['Location'] ?? ''), '?r=pages'), json_encode($r->headers));

    [$anon, , $app2] = admin_setup(loggedIn: false);
    $login = fn (string $accept) => $anon->handle(admin_req('GET', 'login', [], ['headers' => ['accept' => 'text/html', 'accept-language' => $accept]]))->body;
    contains($login('hu-HU,hu;q=0.9'), 'Felhasználónév', 'browser language');
    contains($login('de'), 'User name', 'English when nothing fits');
    Fs::writeJson($app2->varDir . '/settings.json', ['admin_language' => 'hu']);
    [$anon2] = [new Lou\Admin\AdminApp(new App(dirname($app2->varDir)), new Lou\Db\Database((new App(dirname($app2->varDir)))->databaseFile()), new Lou\Admin\ArraySession([]))];
    contains($anon2->handle(admin_req('GET', 'login', [], ['headers' => ['accept' => 'text/html', 'accept-language' => 'en']]))->body, 'Felhasználónév', 'the site language wins over the browser');
});

test('admin: admins set the site-wide admin language; every user can change their own password', function () {
    [$admin, , $app] = admin_setup();
    $html = ['headers' => ['accept' => 'text/html']];
    check($admin->handle(admin_req('POST', 'admin-language', ['admin_language' => 'hu'], $html))->status === 303, 'saved');
    check((Fs::readJson($app->varDir . '/settings.json')['admin_language'] ?? '') === 'hu', 'stored in var/settings.json');
    check($admin->handle(admin_req('POST', 'admin-language', ['admin_language' => 'xx'], $html))->status === 200, 'unknown language refused');
    [$editor, , $app2] = admin_setup('editor');
    check($editor->handle(admin_req('POST', 'admin-language', ['admin_language' => 'hu'], $html))->status === 403, 'editors cannot');

    $r = $editor->handle(admin_req('POST', 'password', ['current_password' => 'kliens-jelszo-12', 'new_password' => 'uj jelszo 12345', 'new_password2' => 'uj jelszo 12345'], $html));
    check($r->status === 303 && (new Users($app2->varDir . '/users.json'))->verify('kliens', 'uj jelszo 12345') === 'editor', 'password changed');
    $r = $editor->handle(admin_req('POST', 'password', ['current_password' => 'nope', 'new_password' => 'x1234567890', 'new_password2' => 'x1234567890'], $html));
    contains($r->body, 'The current password is wrong.');
    $r = $editor->handle(admin_req('POST', 'password', ['current_password' => 'uj jelszo 12345', 'new_password' => 'a1234567890', 'new_password2' => 'b1234567890'], $html));
    contains($r->body, 'The two new passwords are not the same.');
});
