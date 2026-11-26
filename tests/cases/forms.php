<?php

declare(strict_types=1);

use Lou\App;
use Lou\Build\Builder;
use Lou\Db\Database;
use Lou\Env;
use Lou\Forms\FormProcessor;
use Lou\Forms\Token;
use Lou\Http\HttpClient;
use Lou\Http\Request;
use Lou\Integrations;
use Lou\LouException;
use Lou\Mail\Mailer;
use Lou\Mail\Message;
use Lou\Mail\PhpMailerMailer;
use Lou\Support\Fs;

final class FakeHttp implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array, body: ?string}> */
    public array $requests = [];
    /** @var list<array{status: int, body: string}> responses returned in order (last one repeats) */
    public array $responses = [['status' => 200, 'body' => '{}']];

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
}

final class ArrayMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];
    public bool $fail = false;

    public function send(Message $message): void
    {
        if ($this->fail) {
            throw new LouException('SMTP down');
        }
        $this->sent[] = $message;
    }
}

/**
 * A test install with forms configured. $forms overrides the example config's forms.
 * @return array{0: FormProcessor, 1: Database, 2: FakeHttp, 3: ArrayMailer, 4: App, 5: Env}
 */
function forms_setup(array $forms = [], array $env = [], array $config = [], ?int $now = null): array
{
    $root = install();
    $cfgFile = "$root/site/config.php";
    $cfg = array_replace(require $cfgFile, $config);
    $cfg['forms'] = array_replace($cfg['forms'], $forms);
    file_put_contents($cfgFile, '<?php return ' . var_export($cfg, true) . ';');
    file_put_contents("$root/.env.php", '<?php return ' . var_export($env + ['app_key' => str_repeat('k', 40)], true) . ';');
    $app = new App($root);
    $http = new FakeHttp();
    $mailer = new ArrayMailer();
    $envObj = Env::load($app);
    $integrations = new Integrations($app, $envObj, $http);
    $integrations->useMailer($mailer);
    $db = new Database($app->databaseFile());
    return [new FormProcessor($app, $db, $integrations, $now), $db, $http, $mailer, $app, $envObj];
}

function post(array $data, array $headers = ['accept' => 'application/json', 'host' => 'example.com', 'origin' => 'https://example.com'], string $ip = '203.0.113.7'): Request
{
    return new Request('POST', [], $data, $headers, $ip);
}

function contact_data(array $extra = []): array
{
    return $extra + ['form' => 'contact', 'lang' => 'hu', 'name' => 'Teszt Elek', 'email' => 'teszt@example.com',
        'message' => "Sor 1\nÁrvíztűrő", 'consent' => '1'];
}

function count_rows(Database $db, string $table): int
{
    return (int) $db->run("SELECT COUNT(*) FROM $table")->fetchColumn();
}

test('forms: valid submission is stored and emailed with reply-to', function () {
    [$p, $db, , $mailer] = forms_setup();
    $res = $p->handle(post(contact_data()));
    check($res->status === 200, "status {$res->status}: {$res->body}");
    check(json_decode($res->body, true)['ok'] === true, 'ok expected');
    $row = $db->run('SELECT * FROM submissions')->fetch();
    check($row['status'] === 'ok' && json_decode($row['data'], true)['message'] === "Sor 1\nÁrvíztűrő", 'stored data');
    check(strlen((string) $row['ip_hash']) === 32 && !str_contains((string) $row['ip_hash'], '203.0'), 'ip must be hashed');
    check(count($mailer->sent) === 1, 'one email');
    $m = $mailer->sent[0];
    check($m->to === ['info@example.com'] && $m->replyTo === 'teszt@example.com', 'recipients');
    contains($m->subject, 'Új üzenet: Teszt Elek');
    check(!str_contains($m->text, 'phone:'), 'empty fields are left out of the email');
});

test('forms: validation errors are localised and nothing is stored', function () {
    [$p, $db] = forms_setup();
    $res = $p->handle(post(['form' => 'contact', 'lang' => 'hu', 'email' => 'nem-email', 'phone' => 'abc']));
    check($res->status === 422, "status {$res->status}");
    $d = json_decode($res->body, true);
    contains($d['errors']['name'], 'kötelező');
    contains($d['errors']['email'], 'érvényes e-mail');
    contains($d['errors']['phone'], 'telefonszám');
    check(count_rows($db, 'submissions') === 0, 'nothing stored');
});

test('forms: no-JS posts redirect to the localised success page', function () {
    [$p, , , , $app] = forms_setup();
    (new Builder($app))->build();
    $res = $p->handle(post(contact_data(['lang' => 'en']), ['host' => 'example.com']));
    check($res->status === 303 && $res->headers['Location'] === '/en/thank-you/', 'redirect expected, got ' . $res->status . ' ' . json_encode($res->headers));
    $bad = $p->handle(post(['form' => 'contact', 'lang' => 'en'], ['host' => 'example.com', 'referer' => 'https://example.com/en/contact/']));
    check($bad->status === 422, 'error page status');
    contains($bad->body, 'Name is required');
    contains($bad->body, 'href="https://example.com/en/contact/"', 'back link');
    // the site's CSP has style-src 'self': no inline styles, the page links a stylesheet from the endpoint folder
    check(!preg_match('/\sstyle=|<style/i', $bad->body), 'no inline styles on the no-JS result page');
    contains($bad->body, 'href="' . $app->endpoint('message.css') . '?v=');
    check(is_file($app->outputDir() . $app->endpoint('message.css')), 'message.css is built into the endpoint folder');
});

test('forms: honeypot gets a fake success and nothing happens', function () {
    [$p, $db, , $mailer] = forms_setup();
    $res = $p->handle(post(contact_data(['website' => 'http://spam.example'])));
    check(json_decode($res->body, true)['ok'] === true, 'fake ok');
    check(count_rows($db, 'submissions') === 0 && $mailer->sent === [], 'nothing stored or sent');
});

test('forms: min-time token rejects fast or forged submissions', function () {
    $now = 1_800_000_000;
    [$p, $db, , , , $env] = forms_setup([], [], [], $now);
    $tok = new Token($env->appKey());
    $p->handle(post(contact_data(['_token' => $tok->create('contact', $now - 1)])));     // too fast
    $p->handle(post(contact_data(['_token' => $tok->create('newsletter', $now - 60)]))); // other form
    $p->handle(post(contact_data(['_token' => '1799999000.' . str_repeat('a', 64)])));   // forged
    check(count_rows($db, 'submissions') === 0, 'bots must not be stored');
    $p->handle(post(contact_data(['_token' => $tok->create('contact', $now - 10)])));
    check(count_rows($db, 'submissions') === 1, 'human submission stored');
});

test('forms: require_token rejects posts without a token', function () {
    [$p, $db] = forms_setup(['contact' => ['require_token' => true] + (require dirname(__DIR__, 2) . '/site/config.php')['forms']['contact']]);
    $p->handle(post(contact_data()));
    check(count_rows($db, 'submissions') === 0, 'stored without token');
});

test('forms: cross-origin posts are rejected, missing origin allowed', function () {
    [$p, $db] = forms_setup();
    $res = $p->handle(post(contact_data(), ['accept' => 'application/json', 'host' => 'example.com', 'origin' => 'https://evil.example']));
    check($res->status === 403, 'cross-origin must be 403');
    $res = $p->handle(post(contact_data(), ['accept' => 'application/json', 'host' => 'example.com']));
    check($res->status === 200, 'no origin header is allowed');
    check(count_rows($db, 'submissions') === 1, 'one stored');
});

test('forms: rate limit per visitor and form', function () {
    [$p, $db] = forms_setup([], [], ['rate_limit' => ['max' => 2, 'window' => 600]]);
    $codes = [];
    for ($i = 0; $i < 3; $i++) {
        $codes[] = $p->handle(post(contact_data()))->status;
    }
    check($codes === [200, 200, 429], 'codes: ' . implode(',', $codes));
    check($p->handle(post(contact_data(), ip: '198.51.100.1'))->status === 200, 'other visitors unaffected');
    check($p->handle(post(['form' => 'newsletter', 'lang' => 'hu', 'email' => 'a@b.hu', 'consent' => '1']))->status === 200, 'other forms unaffected');
});

test('forms: turnstile is verified server-side', function () {
    $contact = (require dirname(__DIR__, 2) . '/site/config.php')['forms']['contact'];
    [$p, $db, $http] = forms_setup(['contact' => ['captcha' => 'turnstile'] + $contact], ['turnstile_secret' => 'ts-secret']);
    $http->responses = [['status' => 200, 'body' => '{"success":false}']];
    $res = $p->handle(post(contact_data(['cf-turnstile-response' => 'tok'])));
    check($res->status === 422, 'failed captcha must be rejected');
    check($p->handle(post(contact_data()))->status === 422, 'missing captcha token must be rejected');
    $http->responses = [['status' => 200, 'body' => '{"success":true}']];
    check($p->handle(post(contact_data(['cf-turnstile-response' => 'tok'])))->status === 200, 'valid captcha accepted');
    $req = $http->requests[0];
    check($req['url'] === 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'turnstile URL');
    contains((string) $req['body'], 'secret=ts-secret');
    contains((string) $req['body'], 'remoteip=203.0.113.7');
    check(count_rows($db, 'submissions') === 1, 'only the verified one is stored');
});

test('forms: to_by routes the email by a field value, falling back to to', function () {
    $contact = (require dirname(__DIR__, 2) . '/site/config.php')['forms']['contact'];
    $contact['fields']['topic'] = 'in:quote,job,other';
    $contact['actions'] = [['email', 'to' => ['info@example.com'], 'subject' => 'x',
        'to_by' => ['field' => 'topic', 'map' => ['job' => 'hr@example.com', 'quote' => ['sales@example.com', 'boss@example.com']]]]];
    [$p, , , $mailer] = forms_setup(['contact' => $contact]);
    foreach (['job' => ['hr@example.com'], 'quote' => ['sales@example.com', 'boss@example.com'], 'other' => ['info@example.com']] as $topic => $to) {
        $mailer->sent = [];
        check($p->handle(post(contact_data(['topic' => $topic])))->status === 200, "topic $topic accepted");
        check($mailer->sent[0]->to === $to, "$topic → " . implode(',', $mailer->sent[0]->to));
    }
});

test('forms: reCAPTCHA v3 enforces the minimum score', function () {
    $contact = (require dirname(__DIR__, 2) . '/site/config.php')['forms']['contact'];
    [$p, , $http] = forms_setup(['contact' => ['captcha' => 'recaptcha'] + $contact], ['recaptcha_secret' => 's']);
    $http->responses = [['status' => 200, 'body' => '{"success":true,"score":0.2,"action":"submit"}']];
    check($p->handle(post(contact_data(['g-recaptcha-response' => 't'])))->status === 422, 'low score rejected');
    $http->responses = [['status' => 200, 'body' => '{"success":true,"score":0.9,"action":"submit"}']];
    check($p->handle(post(contact_data(['g-recaptcha-response' => 't'])))->status === 200, 'good score accepted');
});

test('forms: Mailchimp subscribe upserts a pending member', function () {
    $nl = ['fields' => ['email' => 'required|email', 'name' => 'max:100'], 'actions' => [['store'], ['subscribe', 'provider' => 'mailchimp', 'list' => 'L1']]];
    [$p, $db, $http] = forms_setup(['newsletter' => $nl], ['mailchimp_key' => 'abc123-us21']);
    $p->handle(post(['form' => 'newsletter', 'lang' => 'hu', 'email' => 'Kati@Example.com', 'name' => 'Kiss Kati']));
    $r = $http->requests[0];
    check($r['method'] === 'PUT', 'PUT expected');
    check($r['url'] === 'https://us21.api.mailchimp.com/3.0/lists/L1/members/' . md5('kati@example.com'), $r['url']);
    check($r['headers']['Authorization'] === 'Basic ' . base64_encode('lou:abc123-us21'), 'auth');
    $b = json_decode((string) $r['body'], true);
    check($b['status_if_new'] === 'pending' && $b['merge_fields'] === ['FNAME' => 'Kiss', 'LNAME' => 'Kati'] && $b['language'] === 'hu', (string) $r['body']);
    check($db->run('SELECT status FROM subscribers')->fetchColumn() === 'synced', 'subscriber synced');
});

test('forms: MailerLite subscribe adds to the group', function () {
    $nl = ['fields' => ['email' => 'required|email'], 'actions' => [['store'], ['subscribe', 'provider' => 'mailerlite', 'group' => '42']]];
    [$p, , $http] = forms_setup(['newsletter' => $nl], ['mailerlite_key' => 'ml-key']);
    $p->handle(post(['form' => 'newsletter', 'lang' => 'en', 'email' => 'a@b.com']));
    $r = $http->requests[0];
    check($r['url'] === 'https://connect.mailerlite.com/api/subscribers' && $r['headers']['Authorization'] === 'Bearer ml-key', 'request');
    check(json_decode((string) $r['body'], true) === ['email' => 'a@b.com', 'groups' => ['42']], (string) $r['body']);
});

test('forms: webhooks are signed when a secret is set', function () {
    $contact = (require dirname(__DIR__, 2) . '/site/config.php')['forms']['contact'];
    $contact['actions'] = [['store'], ['webhook', 'webhook' => 'zap']];
    [$p, , $http] = forms_setup(['contact' => $contact], ['webhooks' => ['zap' => 'https://hooks.example/zap'], 'webhook_secret' => 'whs']);
    $p->handle(post(contact_data()));
    $r = $http->requests[0];
    check($r['url'] === 'https://hooks.example/zap', 'url');
    check($r['headers']['X-Lou-Signature'] === 'sha256=' . hash_hmac('sha256', (string) $r['body'], 'whs'), 'signature');
    $b = json_decode((string) $r['body'], true);
    check($b['event'] === 'submission' && $b['form'] === 'contact' && $b['data']['name'] === 'Teszt Elek', 'payload');
});

test('forms: failed actions keep the submission and retry only what failed', function () {
    $contact = (require dirname(__DIR__, 2) . '/site/config.php')['forms']['contact'];
    $contact['actions'] = [['store'], ['webhook', 'webhook' => 'zap'], ['email', 'to' => ['info@example.com']]];
    [$p, $db, $http, $mailer] = forms_setup(['contact' => $contact], ['webhooks' => ['zap' => 'https://hooks.example/zap']]);
    $mailer->fail = true;
    $res = $p->handle(post(contact_data()));
    check(json_decode($res->body, true)['ok'] === true, 'visitor still sees success: the message is stored');
    check($db->run('SELECT status FROM submissions')->fetchColumn() === 'partial', 'status partial');
    $mailer->fail = false;
    $r = $p->retry();
    check($r === ['retried' => 1, 'fixed' => 1], json_encode($r));
    check(count($mailer->sent) === 1, 'email sent on retry');
    check(count($http->requests) === 1, 'webhook must not be called twice');
    check($db->run('SELECT status FROM submissions')->fetchColumn() === 'ok', 'status ok');
});

test('forms: unknown forms and wrong methods are rejected', function () {
    [$p] = forms_setup();
    check($p->handle(post(['form' => 'nope']))->status === 404, 'unknown form');
    check($p->handle(new Request('GET'))->status === 405, 'GET without token');
    check($p->handle(new Request('POST', [], [], [], '', 999999))->status === 413, 'body too large');
    $t = $p->handle(new Request('GET', ['token' => 'contact']));
    check($t->status === 200 && preg_match('/^\d{10}\.[a-f0-9]{64}$/', json_decode($t->body, true)['token']) === 1, 'token issued');
});

test('forms: PHPMailer builds a UTF-8 message without header injection', function () {
    $m = new PhpMailerMailer(['host' => 'smtp.example.com', 'user' => 'u', 'pass' => 'p'], 'web@example.com', 'Példa Kft.');
    $pm = $m->prepare(new Message(['info@example.com'], 'Új üzenet: Árvíz', "Szöveg ő\n", 'x@example.com', 'Kiss Kati'));
    $pm->preSend();
    $mime = $pm->getSentMIMEMessage();
    contains($mime, 'Subject: =?utf-8?');
    contains($mime, 'Reply-To: Kiss Kati <x@example.com>');
    contains($mime, 'charset=utf-8');
    check($pm->SMTPSecure === 'tls' && $pm->SMTPAuth === true, 'smtp settings');
    $threw = false;
    try {
        $m->prepare(new Message(['info@example.com'], 's', 't', "x@example.com\r\nBcc: evil@example.com"));
    } catch (\Throwable) {
        $threw = true;
    }
    check($threw, 'a reply-to address with a newline must be rejected');
});

test('forms: build wires the endpoint stub, forms.js and form fields', function () {
    $root = install();
    (new Builder(new App($root)))->build();
    $stub = file_get_contents("$root/public/_lou/submit.php");
    contains($stub, var_export($root, true));
    check(!str_contains($stub, '__LOU_ROOT__'), 'placeholder left');
    check(is_file("$root/public/_lou/forms.js"), 'forms.js missing');
    $contact = file_get_contents("$root/public/kapcsolat/index.html");
    contains($contact, 'name="form" value="contact"');
    contains($contact, 'name="lang" value="hu"');
    contains($contact, 'action="/_lou/submit.php" data-form');
    contains($contact, '<script src="/_lou/forms.js" defer></script>');

    file_put_contents("$root/site/templates/page.php", '<?php $v->layout("layout") ?><?= $v->formFields("missing") ?>');
    try {
        (new Builder(new App($root)))->build();
        check(false, 'unknown form should fail the build');
    } catch (LouException $e) {
        contains($e->getMessage(), "unknown form 'missing'");
    }
});

test('fingerprints: the endpoint folder is a setting, and public pages and forms.js do not name Lou', function () {
    $root = install();
    Fs::writeJson("$root/var/settings.json", ['endpoint_dir' => 'x-api']);
    $app = new App($root);
    check($app->endpoint('admin.php') === '/x-api/admin.php', 'endpoint path');
    (new Builder($app))->build();
    check(is_file("$root/public/x-api/submit.php") && is_file("$root/public/x-api/forms.js") && !is_dir("$root/public/_lou"), 'stubs in the set folder');
    $contact = file_get_contents("$root/public/kapcsolat/index.html");
    contains($contact, 'action="/x-api/submit.php" data-form');
    contains($contact, '<script src="/x-api/forms.js" defer></script>');
    contains($contact, '<div hidden><label>Website');
    foreach (array_merge(glob("$root/public/*.html"), glob("$root/public/*/index.html"), ["$root/public/x-api/forms.js"]) as $f) {
        check(stripos((string) file_get_contents($f), 'lou') === false, basename(dirname($f)) . '/' . basename($f) . ' names Lou');
    }

    // site/config.php (or an assistant's draft of it) can't move the endpoints
    file_put_contents("$root/site/config.php", str_replace('return [', "return [\n    'endpoint_dir' => 'elsewhere',", (string) file_get_contents("$root/site/config.php")));
    check((new App($root))->endpoint() === '/x-api', 'only var/settings.json sets endpoint_dir');

    foreach (['', '.x', '-x', 'A_B', 'assets', 'a/b', str_repeat('a', 41)] as $bad) {
        check(!App::validEndpointDir($bad), "'$bad' must be refused");
    }
    Fs::writeJson("$root/var/settings.json", ['endpoint_dir' => '../x']);
    try {
        new App($root);
        check(false, 'a bad endpoint_dir must be refused');
    } catch (LouException) {
    }

    // a page inside the endpoint folder is refused; old Lou form names in the site get a warning
    Fs::writeJson("$root/var/settings.json", ['endpoint_dir' => 'kapcsolat']);
    try {
        (new Builder(new App($root)))->build();
        check(false, 'a page inside the endpoint folder must fail the build');
    } catch (LouException $e) {
        contains($e->getMessage(), 'inside the endpoint folder');
    }
    Fs::writeJson("$root/var/settings.json", []);
    file_put_contents("$root/site/assets/css/site.css", ".lou-hp{display:none}\n", FILE_APPEND);
    $r = (new Builder(new App($root)))->build();
    contains(implode("\n", $r['warnings']), 'Old Lou form names in assets/css/site.css');
});

test('forms: captcha widget needs a site key', function () {
    $root = install();
    $cfg = require "$root/site/config.php";
    $cfg['forms']['contact']['captcha'] = 'turnstile';
    file_put_contents("$root/site/config.php", '<?php return ' . var_export($cfg, true) . ';');
    try {
        (new Builder(new App($root)))->build();
        check(false, 'missing site key should fail the build');
    } catch (LouException $e) {
        contains($e->getMessage(), 'site_key');
    }
    $cfg['captcha']['turnstile']['site_key'] = '0x4AAA';
    file_put_contents("$root/site/config.php", '<?php return ' . var_export($cfg, true) . ';');
    (new Builder(new App($root)))->build();
    $html = file_get_contents("$root/public/kapcsolat/index.html");
    contains($html, 'class="cf-turnstile" data-sitekey="0x4AAA" data-language="hu"');
});
