<?php

declare(strict_types=1);

namespace Lou\Forms;

use Lou\App;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\Http\Response;
use Lou\Integrations;
use Lou\LouException;
use Lou\Mail\Message;
use Lou\Support\Fs;
use Lou\Support\Html;

/**
 * Handles every visitor-facing submission (submit.php in the endpoint folder): contact forms, newsletter sign-ups, anything
 * defined under 'forms' in site/config.php.
 *
 * Pipeline: method/size → form lookup → origin → honeypot → token/min time → rate limit → captcha
 *           → validation → actions (store first, then email / subscribe / webhook) → response.
 */
final class FormProcessor
{
    private const MAX_BODY = 65536;
    private const TOKEN_MAX_AGE = 7200;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly Integrations $integrations,
        private readonly ?int $now = null,
    ) {
    }

    public function handle(Request $req): Response
    {
        if ($req->method === 'GET' && isset($req->query['token'])) {
            return $this->issueToken((string) $req->query['token']);
        }
        if ($req->method !== 'POST') {
            return new Response(405, '', ['Allow' => 'POST']);
        }
        if ($req->contentLength > self::MAX_BODY) {
            return new Response(413);
        }

        $formId = $req->input('form');
        $def = $this->app->config['forms'][$formId] ?? null;
        $lang = in_array($req->input('lang'), $this->app->config['languages'], true)
            ? $req->input('lang') : $this->app->config['default_language'];
        $t = new Strings($this->app, $lang);
        if (!is_array($def)) {
            return Response::json(404, ['ok' => false, 'message' => 'Unknown form']);
        }

        if (!$this->originAllowed($req)) {
            return Response::json(403, ['ok' => false, 'message' => 'Cross-origin submission rejected']);
        }

        // honeypot: bots get a fake success and nothing is stored or sent
        if ($req->input('website') !== '') {
            return $this->success($req, $def, $t);
        }

        // signed min-time token (set by forms.js; optional unless the form requires it)
        $token = $req->input('_token');
        if ($token !== '' || !empty($def['require_token'])) {
            $age = (new Token($this->integrations->env->appKey()))->age($token, $formId, $this->time());
            $min = (int) ($def['min_seconds'] ?? 3);
            if ($age === null || $age < $min || $age > self::TOKEN_MAX_AGE) {
                return $this->success($req, $def, $t); // looks like a bot: fake success
            }
        }

        if (!$this->rateLimit($req, $formId)) {
            return $this->failure($req, 429, $t->get('form.error.rate'), [], $t);
        }

        if (!empty($def['captcha'])) {
            $captcha = $this->integrations->captcha((string) $def['captcha']);
            if (!$captcha->verify($req->input($captcha->field()), $req->ip)) {
                return $this->failure($req, 422, $t->get('form.error.captcha'), [], $t);
            }
        }

        [$data, $errors] = Validator::validate((array) ($def['fields'] ?? []), $req->post);
        if ($errors) {
            $messages = [];
            foreach ($errors as $field => [$key, $params]) {
                $label = $t->has("form.$field") ? $t->get("form.$field") : ucfirst($field);
                $messages[$field] = $t->get($key, $params + ['field' => $label]);
            }
            return $this->failure($req, 422, $t->get('form.error'), $messages, $t);
        }

        try {
            $this->process($formId, $lang, $def, $data, $req);
        } catch (\Throwable $e) {
            $this->log("Submission to '$formId' failed: " . $e->getMessage());
            return $this->failure($req, 500, $t->get('form.error.server'), [], $t);
        }
        return $this->success($req, $def, $t);
    }

    /**
     * Store the submission, then run the remaining actions. Failures after storing are recorded
     * (status "partial") and can be retried with `php lou retry`.
     */
    public function process(string $formId, string $lang, array $def, array $data, ?Request $req = null): int
    {
        $this->db->run(
            'INSERT INTO submissions (form, lang, data, status, results, ip_hash, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$formId, $lang, json_encode($data, JSON_UNESCAPED_UNICODE), 'pending', '[]',
             $req ? $this->ipHash($req->ip) : null, $req ? mb_substr($req->header('user-agent'), 0, 300) : null, gmdate('c', $this->time())],
        );
        $id = (int) $this->db->pdo->lastInsertId();
        $this->runActions($id, $formId, $lang, $def, $data, []);
        return $id;
    }

    /** Re-run failed actions of partial submissions. @return array{retried: int, fixed: int} */
    public function retry(): array
    {
        $rows = $this->db->run("SELECT * FROM submissions WHERE status = 'partial' ORDER BY id")->fetchAll();
        $fixed = 0;
        foreach ($rows as $row) {
            $def = $this->app->config['forms'][$row['form']] ?? null;
            if (!is_array($def)) {
                continue;
            }
            $prev = json_decode($row['results'], true) ?: [];
            $status = $this->runActions((int) $row['id'], $row['form'], $row['lang'], $def, json_decode($row['data'], true) ?: [], $prev);
            $fixed += $status === 'ok' ? 1 : 0;
        }
        return ['retried' => count($rows), 'fixed' => $fixed];
    }

    /**
     * @param array<int, array{action: string, ok: bool, error?: string}> $previous results of an earlier run
     */
    private function runActions(int $id, string $formId, string $lang, array $def, array $data, array $previous): string
    {
        $results = [];
        foreach (array_values((array) ($def['actions'] ?? [])) as $i => $action) {
            $type = (string) ($action[0] ?? $action['type'] ?? '');
            if ($type === 'store') {
                continue; // always stored above
            }
            if (($previous[$i]['ok'] ?? false) === true) {
                $results[$i] = $previous[$i]; // already done, don't send twice
                continue;
            }
            try {
                match ($type) {
                    'email'     => $this->actionEmail($formId, $lang, $action, $data),
                    'subscribe' => $this->actionSubscribe($lang, $action, $data),
                    'webhook'   => $this->integrations->webhook((string) ($action['webhook'] ?? ''))
                        ->post(['event' => 'submission', 'form' => $formId, 'lang' => $lang, 'id' => $id, 'data' => $data]),
                    default     => throw new LouException("Unknown action '$type'"),
                };
                $results[$i] = ['action' => $type, 'ok' => true];
            } catch (\Throwable $e) {
                $results[$i] = ['action' => $type, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
                $this->log("Form '$formId' #$id action '$type' failed: " . $e->getMessage());
            }
        }
        $failed = array_filter($results, fn ($r) => !$r['ok']);
        $status = $failed ? 'partial' : 'ok';
        $this->db->run('UPDATE submissions SET status = ?, results = ? WHERE id = ?',
            [$status, json_encode($results, JSON_UNESCAPED_UNICODE), $id]);
        return $status;
    }

    private function actionEmail(string $formId, string $lang, array $action, array $data): void
    {
        $to = (array) ($action['to'] ?? throw new LouException("Email action of '$formId' needs 'to'"));
        // 'to_by' => ['field' => 'topic', 'map' => ['job' => 'hr@…']]: a field's value picks the recipients;
        // values not in the map (or an empty field) fall back to 'to'.
        if (isset($action['to_by']['field'], $action['to_by']['map'])) {
            $value = (string) ($data[(string) $action['to_by']['field']] ?? '');
            if ($value !== '' && isset($action['to_by']['map'][$value])) {
                $to = (array) $action['to_by']['map'][$value];
            }
        }
        $replace = $data + ['form' => $formId, 'site' => $this->app->config['name']];
        $subject = (string) ($action['subject'] ?? '[{site}] New {form} submission');
        foreach ($replace as $k => $v) {
            $subject = str_replace('{' . $k . '}', (string) $v, $subject);
        }
        $subject = mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 200);

        $lines = [];
        foreach ($data as $k => $v) {
            if ($v === '') {
                continue;
            }
            $lines[] = str_contains($v, "\n") ? "$k:\n$v\n" : "$k: $v";
        }
        $text = implode("\n", $lines) . "\n\n--\nForm: $formId ($lang) · " . gmdate('Y-m-d H:i') . " UTC\n";

        $replyField = (string) ($action['reply_to'] ?? 'email');
        $replyTo = isset($data[$replyField]) && filter_var($data[$replyField], FILTER_VALIDATE_EMAIL) ? $data[$replyField] : null;

        $this->integrations->mailer()->send(new Message($to, $subject, $text, $replyTo, $data['name'] ?? null));
    }

    private function actionSubscribe(string $lang, array $action, array $data): void
    {
        $provider = (string) ($action['provider'] ?? throw new LouException("Subscribe action needs 'provider'"));
        $email = $data[(string) ($action['email_field'] ?? 'email')] ?? '';
        $fields = array_filter(['name' => $data['name'] ?? '', 'lang' => $lang]);
        $list = (string) ($action['list'] ?? $action['group'] ?? '');
        $now = gmdate('c', $this->time());
        try {
            $this->integrations->newsletter($provider, $action)->subscribe($email, $fields);
            $status = 'synced';
            $error = null;
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 500);
        }
        $this->db->run(
            'INSERT INTO subscribers (email, name, provider, list, status, error, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (email, provider, list) DO UPDATE SET status = excluded.status, error = excluded.error, updated_at = excluded.updated_at',
            [$email, $fields['name'] ?? null, $provider, $list, $status, $error, $now, $now],
        );
        if (isset($e) && $status === 'failed') {
            throw $e;
        }
    }

    // ---------------------------------------------------------------- checks

    private function issueToken(string $formId): Response
    {
        if (!isset($this->app->config['forms'][$formId])) {
            return Response::json(404, ['ok' => false]);
        }
        return Response::json(200, ['token' => (new Token($this->integrations->env->appKey()))->create($formId, $this->time())]);
    }

    /** Same-origin check via Origin (or Referer). Requests without either are allowed (old browsers, no-JS posts). */
    private function originAllowed(Request $req): bool
    {
        $source = $req->header('origin') ?: $req->header('referer');
        if ($source === '' || $source === 'null') {
            return $source === '';
        }
        $host = strtolower((string) parse_url($source, PHP_URL_HOST));
        $allowed = array_map('strtolower', array_filter([
            $req->header('host') ? explode(':', $req->header('host'))[0] : null,
            parse_url((string) $this->app->config['base_url'], PHP_URL_HOST),
            ...(array) ($this->app->config['allowed_origins'] ?? []),
        ]));
        return in_array($host, $allowed, true);
    }

    private function rateLimit(Request $req, string $formId): bool
    {
        $cfg = (array) ($this->app->config['rate_limit'] ?? []);
        $max = (int) ($cfg['max'] ?? 5);
        $window = (int) ($cfg['window'] ?? 600);
        $now = $this->time();
        $key = $this->ipHash($req->ip) . '|' . $formId;

        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE ts < ?', [$now - 86400]);
        }
        $count = (int) $this->db->run('SELECT COUNT(*) FROM rate_limits WHERE key = ? AND ts > ?', [$key, $now - $window])->fetchColumn();
        if ($count >= $max) {
            return false;
        }
        $this->db->run('INSERT INTO rate_limits (key, ts) VALUES (?, ?)', [$key, $now]);
        return true;
    }

    /** Visitor IPs are never stored in clear text. */
    private function ipHash(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, $this->integrations->env->appKey()), 0, 32);
    }

    // ---------------------------------------------------------------- responses

    private function success(Request $req, array $def, Strings $t): Response
    {
        if ($req->wantsJson()) {
            return Response::json(200, ['ok' => true, 'message' => $t->get((string) ($def['success_message'] ?? 'form.success'))]);
        }
        $page = $def['success']['page'] ?? null;
        if (is_string($page) && ($url = $this->pageUrl($page, $t->lang)) !== null) {
            return Response::redirect($url);
        }
        return Response::html(200, $this->messagePage($t, $t->get('form.success'), []));
    }

    /** @param array<string, string> $fieldErrors */
    private function failure(Request $req, int $status, string $message, array $fieldErrors, Strings $t): Response
    {
        if ($req->wantsJson()) {
            return Response::json($status, ['ok' => false, 'message' => $message, 'errors' => (object) $fieldErrors]);
        }
        return Response::html($status, $this->messagePage($t, $message, $fieldErrors, $req->header('referer')));
    }

    /** Minimal page for visitors without JavaScript. */
    private function messagePage(Strings $t, string $message, array $errors, string $back = ''): string
    {
        $items = '';
        foreach ($errors as $e) {
            $items .= '<li>' . Html::escape($e) . '</li>';
        }
        $backLink = $back !== '' && $this->originAllowed(new Request('GET', headers: ['referer' => $back]))
            ? '<p><a href="' . Html::escape($back) . '">' . Html::escape($t->get('form.back')) . '</a></p>' : '';
        return '<!doctype html><html lang="' . Html::escape($t->lang) . '"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . Html::escape($message) . '</title>'
            . '<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem">'
            . '<p>' . Html::escape($message) . '</p>' . ($items ? "<ul>$items</ul>" : '') . $backLink . '</body></html>';
    }

    private function pageUrl(string $pageId, string $lang): ?string
    {
        $file = $this->app->varDir . '/manifest.json';
        $manifest = is_file($file) ? Fs::readJson($file) : [];
        return $manifest["$pageId|$lang"] ?? null;
    }

    private function time(): int
    {
        return $this->now ?? time();
    }

    private function log(string $msg): void
    {
        Fs::ensureDir($this->app->varDir);
        @file_put_contents($this->app->varDir . '/error.log', gmdate('c') . " $msg\n", FILE_APPEND | LOCK_EX);
    }
}
