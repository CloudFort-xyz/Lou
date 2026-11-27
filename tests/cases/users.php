<?php

declare(strict_types=1);

use Lou\Admin\AdminApp;
use Lou\Admin\ArraySession;
use Lou\Admin\PasswordPolicy;
use Lou\Admin\Users;
use Lou\App;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\LouException;

/** An AdminApp signed in as $user (or logged out), with an ArrayMailer catching invite and reset emails. */
function users_admin(App $app, ?string $user, ?ArrayMailer $mailer = null, ?int $now = null): array
{
    $t = $now ?? time();
    $users = new Users($app->varDir . '/users.json');
    $session = new ArraySession($user !== null ? ['user' => $user, 'role' => (string) $users->role($user), 'sv' => $users->sessionVersion($user),
        'started' => $t, 'seen' => $t, 'csrf' => 'tok'] : []);
    $admin = new AdminApp($app, new Database($app->databaseFile()), $session, $now);
    $integrations = new \Lou\Integrations($app, \Lou\Env::load($app));
    $integrations->useMailer($mailer ?? new ArrayMailer());
    $admin->useIntegrations($integrations);
    return [$admin, $session];
}

/** The token from the welcome link in an email body or a page. */
function users_token(string $text): string
{
    check((bool) preg_match('/r=welcome&(?:amp;)?t=([a-f0-9]{64})/', $text, $m), "no welcome link in: $text");
    return $m[1];
}

const HTML = ['headers' => ['accept' => 'text/html']];

test('users: roles are ranked, and contributors edit and submit but never publish or see submissions', function () {
    check(Users::atLeast('admin', 'editor') && Users::atLeast('editor', 'contributor') && !Users::atLeast('contributor', 'editor')
        && !Users::atLeast('nobody', 'contributor'), 'ranking');
    [, , $app] = admin_setup();
    (new Users($app->varDir . '/users.json'))->add('anna', 'anna jelszava 12', 'contributor');
    [$anna] = users_admin($app, 'anna');
    $r = $anna->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'hero.title', 'type' => 'text', 'value' => 'Anna címe']));
    check($r->status === 200, $r->body);
    check($anna->handle(admin_req('POST', 'submit', ['summary' => 'Anna'], HTML))->status === 303, 'contributors submit');
    foreach (['POST publish', 'POST reject', 'POST staging-revert', 'GET submissions', 'GET export', 'GET subscribers', 'GET users', 'GET assistants'] as $route) {
        [$m, $r] = explode(' ', $route);
        check($anna->handle(admin_req($m, $r, [], HTML))->status === 403, "contributors can't $route");
    }
    $review = $anna->handle(admin_req('GET', 'review', [], HTML))->body;
    contains($review, 'An editor or administrator checks these changes and publishes them.');
    check(!str_contains($review, 'r=publish') && !str_contains($review, 'r=staging-revert'), 'no publish or staging undo for contributors');
    $dash = $anna->handle(admin_req('GET', 'dashboard', [], HTML))->body;
    check(!str_contains($dash, 'r=submissions') && !str_contains($dash, 'r=users'), 'menu and dashboard hide submissions and users');
    contains($dash, '<span class="topbar-role">contributor</span>');
});

test('users: role changes and removals apply at once, even to someone signed in', function () {
    [$admin, , $app] = admin_setup();
    [$kliens, $session] = users_admin($app, 'kliens');
    check($kliens->handle(admin_req('GET', 'submissions', [], HTML))->status === 200, 'editor sees submissions');
    check($admin->handle(admin_req('POST', 'user-update', ['name' => 'kliens', 'role' => 'contributor', 'email' => ''], HTML))->status === 303, 'demoted');
    check($kliens->handle(admin_req('GET', 'submissions', [], HTML))->status === 403, 'the open session lost the right at once');
    check($admin->handle(admin_req('POST', 'user-remove', ['name' => 'kliens'], HTML))->status === 303, 'removed');
    $r = $kliens->handle(admin_req('GET', 'dashboard', [], HTML));
    check($r->status === 303 && $session->get('user') === null, 'a removed user is signed out on the next request');
});

test('users: a new password and "sign out everywhere" end other sessions but keep the current one', function () {
    [, , $app] = admin_setup();
    [$laptop] = users_admin($app, 'kliens');
    [$phone, $phoneSession] = users_admin($app, 'kliens');
    $r = $laptop->handle(admin_req('POST', 'password', ['current_password' => 'kliens-jelszo-12', 'new_password' => 'uj jelszo 12345', 'new_password2' => 'uj jelszo 12345'], HTML));
    check($r->status === 303, $r->body);
    check($laptop->handle(admin_req('GET', 'dashboard', [], HTML))->status === 200, 'the session that changed it stays');
    check($phone->handle(admin_req('GET', 'dashboard', [], HTML))->status === 303 && $phoneSession->get('user') === null, 'the other one ends');

    [$boss] = users_admin($app, 'bence');
    [$boss2, $boss2Session] = users_admin($app, 'bence');
    check($boss->handle(admin_req('POST', 'user-signout', ['name' => 'bence'], HTML))->status === 303, 'signed out everywhere');
    check($boss->handle(admin_req('GET', 'dashboard', [], HTML))->status === 200, 'this browser stays signed in');
    check($boss2->handle(admin_req('GET', 'dashboard', [], HTML))->status === 303 && $boss2Session->get('user') === null, 'other sessions end');
    $boss->handle(admin_req('POST', 'user-signout', ['name' => 'kliens'], HTML));
    check($laptop->handle(admin_req('GET', 'dashboard', [], HTML))->status === 303, 'an admin signs someone else out');
});

test('users: the last administrator can\'t be demoted or removed, and nobody removes themselves or changes their own role', function () {
    [$admin, , $app] = admin_setup();
    $users = new Users($app->varDir . '/users.json');
    foreach ([fn () => $users->setRole('bence', 'editor'), fn () => $users->remove('bence'), fn () => $users->add('bence', 'correct horse battery', 'editor')] as $fn) {
        try {
            $fn();
            check(false, 'the last admin must stay');
        } catch (LouException $e) {
            contains($e->getMessage(), 'Make someone else an administrator');
        }
    }
    $r = $admin->handle(admin_req('POST', 'user-remove', ['name' => 'bence'], HTML));
    contains($r->body, 'You can’t remove yourself.');
    $r = $admin->handle(admin_req('POST', 'user-update', ['name' => 'bence', 'role' => 'editor', 'email' => ''], HTML));
    contains($r->body, 'You can’t change your own role.');
    // with a second admin, the first one can be demoted by the second
    $users->setRole('kliens', 'admin');
    [$kliens] = users_admin($app, 'kliens');
    check($kliens->handle(admin_req('POST', 'user-update', ['name' => 'bence', 'role' => 'editor', 'email' => 'b@pelda.hu'], HTML))->status === 303, 'demoted');
    check($users->role('bence') === 'editor' && $users->get('bence')['email'] === 'b@pelda.hu', 'role and email saved');
    $page = $kliens->handle(admin_req('GET', 'user', [], ['query' => ['name' => 'kliens'], 'headers' => ['accept' => 'text/html']]))->body;
    contains($page, 'You can’t change your own role.');
    check(!str_contains($page, 'r=user-remove'), 'no remove button for yourself or the last admin');
});

test('users: invites email a one-time link where the person chooses their own password and is signed in', function () {
    [, , $app] = admin_setup();
    $mailer = new ArrayMailer();
    [$admin, $session] = users_admin($app, 'bence', $mailer);
    $r = $admin->handle(admin_req('POST', 'user-invite', ['name' => 'Anna', 'email' => 'anna@pelda.hu', 'role' => 'contributor'], HTML));
    check($r->status === 303 && count($mailer->sent) === 1 && $mailer->sent[0]->to === ['anna@pelda.hu'], $r->body);
    contains($mailer->sent[0]->subject, 'You are invited to edit');
    contains($mailer->sent[0]->text, 'Your user name is anna.');
    $token = users_token($mailer->sent[0]->text);
    $users = new Users($app->varDir . '/users.json');
    check($users->get('anna')['hash'] === '' && $users->get('anna')['link']['hash'] === hash('sha256', $token), 'only the hash is stored');
    check(!str_contains((string) file_get_contents($app->varDir . '/users.json'), $token), 'the token is not on disk');
    $list = $admin->handle(admin_req('GET', 'users', [], HTML))->body;
    contains($list, 'Invite sent to anna@pelda.hu.');
    contains($list, '<span class="badge badge-warn">invited</span>');
    check(!str_contains($admin->handle(admin_req('GET', 'users', [], HTML))->body, $token), 'the link is shown once');
    check($admin->handle(admin_req('POST', 'user-invite', ['name' => 'anna', 'email' => 'x@pelda.hu', 'role' => 'editor'], HTML))->status === 200, 'no duplicate names');

    [$guest, $guestSession] = users_admin($app, null);
    check($guest->handle(admin_req('POST', 'login', ['user' => 'anna', 'password' => ''], HTML))->status === 401, 'no login before a password is chosen');
    contains($guest->handle(admin_req('GET', 'welcome', [], ['query' => ['t' => $token], 'headers' => ['accept' => 'text/html']]))->body, 'Welcome to');
    $r = $guest->handle(admin_req('POST', 'welcome', ['t' => $token, 'password' => '1234567890', 'password2' => '1234567890'], HTML));
    contains($r->body, 'This password is too easy to guess');
    $r = $guest->handle(admin_req('POST', 'welcome', ['t' => $token, 'password' => 'anna titkos kertje', 'password2' => 'anna titkos kertje'], HTML));
    check($r->status === 303 && $guestSession->get('user') === 'anna' && $guestSession->get('role') === 'contributor', $r->body);
    check($users->verify('anna', 'anna titkos kertje') === 'contributor' && !isset($users->get('anna')['link']), 'password set, link used up');
    check($guest->handle(admin_req('GET', 'welcome', [], ['query' => ['t' => $token], 'headers' => ['accept' => 'text/html']]))->status === 410, 'the link works once');
});

test('users: reset links expire, replace older links, and are shown to the admin when no email goes out', function () {
    [, , $app] = admin_setup();
    $users = new Users($app->varDir . '/users.json');
    $old = $users->link('kliens', 'reset', 'bence', time(), Users::INVITE_TTL);
    [$admin] = users_admin($app, 'bence');
    // kliens has no email address: the admin gets the link to pass on
    $r = $admin->handle(admin_req('POST', 'user-link', ['name' => 'kliens'], HTML));
    check($r->status === 303, $r->body);
    $page = $admin->handle(admin_req('GET', 'user', [], ['query' => ['name' => 'kliens'], 'headers' => ['accept' => 'text/html']]))->body;
    contains($page, 'Send this link to kliens yourself.');
    $token = users_token($page);
    check($users->findByToken($old, time()) === null && $users->findByToken($token, time()) === 'kliens', 'a new link replaces the old one');
    check($users->findByToken($token, time() + Users::INVITE_TTL + 1) === null, 'links expire');
    check($users->verify('kliens', 'kliens-jelszo-12') === 'editor', 'the old password works until a new one is chosen');
});

test('users: "forgot password" answers the same for every account and is rate limited', function () {
    [, , $app] = admin_setup();
    (new Users($app->varDir . '/users.json'))->setEmail('kliens', 'kliens@pelda.hu');
    $mailer = new ArrayMailer();
    [$guest] = users_admin($app, null, $mailer);
    $known = $guest->handle(admin_req('POST', 'forgot', ['user' => 'KLIENS@pelda.hu'], HTML));
    $unknown = $guest->handle(admin_req('POST', 'forgot', ['user' => 'nobody'], HTML));
    $noEmail = $guest->handle(admin_req('POST', 'forgot', ['user' => 'bence'], HTML));
    check($known->status === 200 && $unknown->status === 200 && $noEmail->status === 200, 'same status');
    $msg = 'If that account exists and has an email address, a link to set a new password is on its way.';
    contains($known->body, $msg);
    contains($unknown->body, $msg);
    check(count($mailer->sent) === 1 && $mailer->sent[0]->to === ['kliens@pelda.hu'], 'only the real account with an email gets one');
    contains($mailer->sent[0]->text, 'expires in 1 hour');
    $token = users_token($mailer->sent[0]->text);
    check((new Users($app->varDir . '/users.json'))->findByToken($token, time() + 3601) === null, 'forgot links last an hour');
    $guest->handle(admin_req('POST', 'forgot', ['user' => 'nobody'], HTML));
    $guest->handle(admin_req('POST', 'forgot', ['user' => 'nobody'], HTML));
    check($guest->handle(admin_req('POST', 'forgot', ['user' => 'kliens'], HTML))->status === 429, '5 requests an hour per IP');
    contains($guest->handle(admin_req('GET', 'login', [], HTML))->body, 'r=forgot');
});

test('users: failed logins are limited per account too, from any IP', function () {
    [, , $app] = admin_setup();
    [$guest] = users_admin($app, null);
    for ($i = 0; $i < 20; $i++) {
        $ip = "198.51.100.$i";
        $guest->handle(new Request('POST', ['r' => 'login'], ['user' => 'bence', 'password' => 'guess'], ['host' => 'example.com'], $ip, 0, [], https: true));
    }
    $r = $guest->handle(new Request('POST', ['r' => 'login'], ['user' => 'bence', 'password' => 'correct horse battery'], ['host' => 'example.com'], '198.51.100.99', 0, [], https: true));
    check($r->status === 429, 'the account is limited for an hour, got ' . $r->status);
    $r = $guest->handle(new Request('POST', ['r' => 'login'], ['user' => 'kliens', 'password' => 'kliens-jelszo-12'], ['host' => 'example.com'], '198.51.100.99', 0, [], https: true));
    check($r->status === 303, 'other accounts are not affected');
    check(!empty((new Users($app->varDir . '/users.json'))->get('kliens')['last_login']), 'last login is recorded');
});

test('users: common passwords and ones built from the user name are refused', function () {
    check(PasswordPolicy::isCommon('Password123') && PasswordPolicy::isCommon('aaaaaaaaaaaa') && PasswordPolicy::isCommon('bence12345', 'bence'), 'refused');
    check(!PasswordPolicy::isCommon('correct horse battery', 'bence') && !PasswordPolicy::isCommon('bence loves long walks', 'bence'), 'accepted');
});

test('cli: user invite prints a one-time link, user list shows invited users, signout ends sessions', function () {
    $root = install();
    $lou = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/lou') . ' --root=' . escapeshellarg($root);
    exec("$lou user invite anna --email=anna@pelda.hu --role=contributor 2>&1", $out, $code);
    check($code === 0, implode("\n", $out));
    $token = users_token(implode("\n", $out));
    check((new Users("$root/var/users.json"))->findByToken($token, time()) === 'anna', 'the printed link works');
    exec("$lou user list 2>&1", $list);
    check((bool) preg_match('/^anna\s+contributor\s+-\s+\S+\s+invited\s+anna@pelda\.hu$/m', implode("\n", $list)), implode("\n", $list));
    exec("$lou user signout anna 2>&1", $o2, $c2);
    check($c2 === 0 && (new Users("$root/var/users.json"))->sessionVersion('anna') === 1, implode("\n", $o2));
});

test('users: assistant tokens remember who created them', function () {
    [$admin, , $app] = admin_setup();
    $admin->handle(admin_req('POST', 'token-add', ['name' => 'Claude'], HTML));
    $all = (new \Lou\Bridge\Tokens($app->varDir . '/tokens.json'))->all();
    check(array_values($all)[0]['by'] === 'bence', json_encode($all));
    contains($admin->handle(admin_req('GET', 'assistants', [], HTML))->body, '<td class="hide-sm">bence</td>');
});
