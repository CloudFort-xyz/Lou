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

test('totp: codes match RFC 6238, work once, allow one step of drift, and the QR code holds the otpauth link', function () {
    $secret = \Lou\Admin\Totp::base32Encode('12345678901234567890');
    check($secret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
    // RFC 6238 appendix B (SHA-1), last 6 digits
    foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
        check(\Lou\Admin\Totp::code($secret, intdiv($time, 30)) === $code, "code at $time");
    }
    check(\Lou\Admin\Totp::verify($secret, '287 082', 59) === 1, 'spaces are ignored');
    check(\Lou\Admin\Totp::verify($secret, '287082', 59 + 30) === 1 && \Lou\Admin\Totp::verify($secret, '287082', 59 + 60) === null, 'one step of drift');
    check(\Lou\Admin\Totp::verify($secret, '287082', 59, 1) === null, 'a used step is refused');
    check(\Lou\Admin\Totp::base32Decode(\Lou\Admin\Totp::base32Encode("\x00\xffabc")) === "\x00\xffabc", 'base32 round trip');
    $svg = \Lou\Support\QrCode::encode(\Lou\Admin\Totp::uri($secret, 'bence', 'Példa Kft.'))->svg(200, 'QR');
    check(str_starts_with($svg, '<svg') && str_contains($svg, 'aria-label="QR"') && !str_contains($svg, 'style'), 'an SVG without inline styles');
    // a known small symbol: "HELLO" in byte mode is version 1 (21×21) with finder patterns in three corners
    $m = \Lou\Support\QrCode::encode('HELLO')->modules();
    check(count($m) === 21 && $m[0][0] && $m[0][6] && !$m[1][1] && $m[2][2] && $m[0][20] && $m[20][0] && !$m[7][7], 'version 1 with finder patterns');
});

/** Turn on two-step login for a user through the Profile page. @return array{0: string, 1: list<string>} secret and recovery codes */
function users_enable_totp(AdminApp $admin, ArraySession $session, int $now): array
{
    $admin->handle(admin_req('POST', 'totp-start', [], HTML));
    $secret = (string) $session->get('totp_setup');
    $page = $admin->handle(admin_req('GET', 'profile', [], HTML))->body;
    contains($page, '<svg');
    contains($page, \Lou\Admin\Totp::grouped($secret));
    $bad = $admin->handle(admin_req('POST', 'totp-enable', ['code' => '000000'], HTML))->body;
    contains($bad, 'That code is not right.');
    $r = $admin->handle(admin_req('POST', 'totp-enable', ['code' => \Lou\Admin\Totp::code($secret, intdiv($now, 30))], HTML));
    check($r->status === 303, $r->body);
    $page = $admin->handle(admin_req('GET', 'profile', [], HTML))->body;
    check((bool) preg_match('#<pre class="code" id="recovery-codes">([^<]+)</pre>#', $page, $m), 'recovery codes shown');
    check(!str_contains($admin->handle(admin_req('GET', 'profile', [], HTML))->body, 'id="recovery-codes"'), 'shown once');
    return [$secret, explode("\n", $m[1])];
}

test('totp: setup on Profile, then login asks for the code; recovery codes work once; wrong codes are rate limited', function () {
    [, , $app] = admin_setup();
    $now = time();
    [$admin, $session] = users_admin($app, 'kliens', null, $now);
    [$secret, $codes] = users_enable_totp($admin, $session, $now);
    check(count($codes) === 10 && (new Users($app->varDir . '/users.json'))->hasTotp('kliens'), 'on, with 10 codes');
    check(!str_contains((string) file_get_contents($app->varDir . '/users.json'), $codes[0]), 'recovery codes are hashed');

    [$guest, $gs] = users_admin($app, null, null, $now + 60);
    $r = $guest->handle(admin_req('POST', 'login', ['user' => 'kliens', 'password' => 'kliens-jelszo-12'], HTML));
    contains($r->body, 'Two-step login');
    check($gs->get('user') === null && $gs->get('pending')['user'] === 'kliens', 'not signed in until the code is right');
    check($guest->handle(admin_req('GET', 'dashboard', [], HTML))->status === 303, 'the admin stays closed');
    check($guest->handle(admin_req('POST', 'login-code', ['code' => '000000'], HTML))->status === 401, 'wrong code');
    $r = $guest->handle(admin_req('POST', 'login-code', ['code' => \Lou\Admin\Totp::code($secret, intdiv($now + 60, 30))], HTML));
    check($r->status === 303 && $gs->get('user') === 'kliens', $r->body);

    // the same code can't be used again, a recovery code works once
    [$g2, $gs2] = users_admin($app, null, null, $now + 60);
    $g2->handle(admin_req('POST', 'login', ['user' => 'kliens', 'password' => 'kliens-jelszo-12'], HTML));
    check($g2->handle(admin_req('POST', 'login-code', ['code' => \Lou\Admin\Totp::code($secret, intdiv($now + 60, 30))], HTML))->status === 401, 'no replay');
    check($g2->handle(admin_req('POST', 'login-code', ['code' => strtoupper($codes[3])], HTML))->status === 303 && $gs2->get('user') === 'kliens', 'recovery code');
    check((new Users($app->varDir . '/users.json'))->recoveryLeft('kliens') === 9, 'used up');
    [$g3] = users_admin($app, null, null, $now + 60);
    $g3->handle(admin_req('POST', 'login', ['user' => 'kliens', 'password' => 'kliens-jelszo-12'], HTML));
    check($g3->handle(admin_req('POST', 'login-code', ['code' => $codes[3]], HTML))->status === 401, 'a recovery code works once');

    // the pending step expires after five minutes
    [$g4] = users_admin($app, null, null, $now);
    $g4->handle(admin_req('POST', 'login', ['user' => 'kliens', 'password' => 'kliens-jelszo-12'], HTML));
    [$late] = [new AdminApp($app, new Database($app->databaseFile()), (fn () => $this->session)->call($g4), $now + 301)];
    contains($late->handle(admin_req('POST', 'login-code', ['code' => $codes[5]], HTML))->body, 'That took too long.');

    // a password reset link does not skip the code
    $token = (new Users($app->varDir . '/users.json'))->link('kliens', 'reset', 'bence', $now, Users::INVITE_TTL);
    [$g5, $gs5] = users_admin($app, null, null, $now);
    $r = $g5->handle(admin_req('POST', 'welcome', ['t' => $token, 'password' => 'uj kliens jelszo', 'password2' => 'uj kliens jelszo'], HTML));
    check($r->status === 200 && $gs5->get('user') === null && $gs5->get('pending')['user'] === 'kliens', 'reset link still asks for the code');

    // turning it off needs the password
    [$k] = users_admin($app, 'kliens', null, $now);
    contains($k->handle(admin_req('POST', 'totp-disable', ['password' => 'wrong'], HTML))->body, 'The current password is wrong.');
    check($k->handle(admin_req('POST', 'totp-disable', ['password' => 'uj kliens jelszo'], HTML))->status === 303, 'off');
    check(!(new Users($app->varDir . '/users.json'))->hasTotp('kliens'), 'off');
});

test('totp: admins can require it for everyone (after turning it on themselves) and reset it for a lost phone', function () {
    [, , $app] = admin_setup();
    $now = time();
    [$admin, $session] = users_admin($app, 'bence', null, $now);
    contains($admin->handle(admin_req('POST', 'users-2fa-policy', ['require_2fa' => '1'], HTML))->body, 'Turn on two-step login for yourself first');
    users_enable_totp($admin, $session, $now);
    check($admin->handle(admin_req('POST', 'users-2fa-policy', ['require_2fa' => '1'], HTML))->status === 303, 'required');
    check((new App($app->root))->settings()['require_2fa'] === true, 'stored in var/settings.json');

    // someone without it can only set it up
    [$kliens, $ks] = users_admin($app, 'kliens', null, $now);
    $r = $kliens->handle(admin_req('GET', 'pages', [], HTML));
    check($r->status === 303 && str_contains((string) ($r->headers['Location'] ?? ''), 'r=profile'), 'sent to Profile');
    check($kliens->handle(admin_req('POST', 'save', ['page' => 'about', 'lang' => 'hu', 'field' => 'title', 'type' => 'text', 'value' => 'x']))->status === 403, 'no edits');
    contains($kliens->handle(admin_req('GET', 'profile', [], HTML))->body, 'This site requires two-step login.');
    users_enable_totp($kliens, $ks, $now);
    check($kliens->handle(admin_req('GET', 'pages', [], HTML))->status === 200, 'free to work once set up');
    contains($kliens->handle(admin_req('POST', 'totp-disable', ['password' => 'kliens-jelszo-12'], HTML))->body, 'it can’t be turned off');

    // site/config.php (which an assistant's draft can change) can't switch the requirement off
    file_put_contents("$app->root/site/config.php", str_replace("'base_url'", "'require_2fa' => false,\n    'base_url'", (string) file_get_contents("$app->root/site/config.php")));
    check((new App($app->root))->config['require_2fa'] === true, 'the server setting wins over site/config.php');
    check($kliens->handle(admin_req('GET', 'pages', [], HTML))->status === 200, 'still allowed (has 2FA)');

    // lost phone: the admin resets it, the user is signed out
    check($admin->handle(admin_req('POST', 'user-2fa-reset', ['name' => 'kliens'], HTML))->status === 303, 'reset');
    check(!(new Users($app->varDir . '/users.json'))->hasTotp('kliens'), 'off');
    check($kliens->handle(admin_req('GET', 'pages', [], HTML))->status === 303 && $ks->get('user') === null, 'signed out');
    $list = $admin->handle(admin_req('GET', 'users', [], HTML))->body;
    contains($list, 'name="require_2fa" value="1" checked');
    contains($list, '<span class="badge">on</span>');
});

test('users: assistants live under Users as a tab', function () {
    [$admin] = admin_setup();
    $page = $admin->handle(admin_req('GET', 'assistants', [], HTML))->body;
    contains($page, '<nav class="tabs"');
    check(!str_contains($page, '?r=assistants"><svg'), 'no separate Assistants menu item');
    check((bool) preg_match('#\?r=users" aria-current="page"><svg#', $page), 'Users is the current menu item on the assistants tab');
    contains($admin->handle(admin_req('GET', 'users', [], HTML))->body, 'Manage assistants');
});
