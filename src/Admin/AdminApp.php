<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\App;
use Lou\Backup\Backup;
use Lou\Build\Builder;
use Lou\Content\ContentStore;
use Lou\Content\Page;
use Lou\Content\Site;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\Http\Response;
use Lou\LouException;
use Lou\Support\Fs;
use Lou\Support\Html;

/**
 * The admin at /_lou/admin.php. Routes by ?r=…; GET renders pages, POST changes things
 * (always with a CSRF token and a same-origin check).
 */
final class AdminApp
{
    private const IDLE_TIMEOUT = 7200;
    private const ABSOLUTE_TIMEOUT = 43200;
    private const SELF = '/_lou/admin.php';

    /** Routes and the minimum role they need (null = public). */
    private const ROUTES = [
        'GET login'           => null,
        'POST login'          => null,
        'POST logout'         => 'editor',
        'GET pages'           => 'editor',
        'GET edit'            => 'editor',
        'GET meta'            => 'editor',
        'POST save'           => 'editor',
        'POST image'          => 'editor',
        'POST meta'           => 'editor',
        'POST publish'        => 'editor',
        'POST share'          => 'editor',
        'GET submissions'     => 'editor',
        'GET submission'      => 'editor',
        'GET export'          => 'editor',
        'GET subscribers'     => 'editor',
        'POST delete-submission' => 'admin',
        'GET builds'          => 'admin',
        'POST rollback'       => 'admin',
        'GET backups'         => 'admin',
        'POST backup'         => 'admin',
        'GET download'        => 'admin',
        'POST restore'        => 'admin',
        'GET redirects'       => 'admin',
        'POST redirect'       => 'admin',
        'POST revoke'         => 'admin',
    ];

    private Request $req;

    public function __construct(
        private readonly App $app,
        private Database $db,
        private readonly SessionStore $session,
        private readonly ?int $now = null,
    ) {
    }

    public function handle(Request $req): Response
    {
        $this->req = $req;
        $route = (string) ($req->query['r'] ?? 'pages');
        $key = "{$req->method} $route";
        if (!array_key_exists($key, self::ROUTES)) {
            return Response::html(404, 'Not found');
        }
        $need = self::ROUTES[$key];

        if ($need !== null) {
            if (!$this->user()) {
                return $req->wantsJson() ? Response::json(401, ['ok' => false, 'message' => 'Please log in again'])
                    : Response::redirect(self::SELF . '?r=login');
            }
            if ($need === 'admin' && $this->role() !== 'admin') {
                return $this->deny();
            }
        }
        if ($req->method === 'POST') {
            if (!$this->sameOrigin() || ($route !== 'login' && !hash_equals($this->csrf(), $req->input('_csrf') ?: $req->header('x-lou-csrf')))) {
                return $req->wantsJson() ? Response::json(403, ['ok' => false, 'message' => 'Security check failed, reload the page']) : $this->deny();
            }
        }

        try {
            $method = 'r' . str_replace('-', '', ucwords(strtolower($req->method) . '-' . $route, '-'));
            return $this->$method();
        } catch (LouException $e) {
            return $req->wantsJson()
                ? Response::json(422, ['ok' => false, 'message' => $e->getMessage()])
                : $this->page('Error', 'message', ['message' => $e->getMessage(), 'error' => true]);
        }
    }

    // ---------------------------------------------------------------- auth

    private function rGetLogin(): Response
    {
        if ($this->user()) {
            return Response::redirect(self::SELF);
        }
        return $this->page('Log in', 'login', ['error' => null]);
    }

    private function rPostLogin(): Response
    {
        $ipKey = 'login|' . substr(hash('sha256', $this->req->ip), 0, 32);
        $t = $this->time();
        $tries = (int) $this->db->run('SELECT COUNT(*) FROM rate_limits WHERE key = ? AND ts > ?', [$ipKey, $t - 900])->fetchColumn();
        if ($tries >= 10) {
            return $this->page('Log in', 'login', ['error' => 'Too many attempts. Try again in 15 minutes.'], 429);
        }
        $name = trim($this->req->input('user'));
        $role = (new Users($this->app->varDir . '/users.json'))->verify($name, $this->req->input('password'));
        if ($role === null) {
            $this->db->run('INSERT INTO rate_limits (key, ts) VALUES (?, ?)', [$ipKey, $t]);
            $this->db->audit('admin', 'login-failed', null, mb_substr($name, 0, 64));
            return $this->page('Log in', 'login', ['error' => 'Wrong user name or password.'], 401);
        }
        $this->session->regenerate();
        $this->session->set('user', strtolower($name));
        $this->session->set('role', $role);
        $this->session->set('started', $t);
        $this->session->set('seen', $t);
        $this->session->set('csrf', bin2hex(random_bytes(32)));
        $this->db->audit('admin', 'login', null, strtolower($name));
        return Response::redirect(self::SELF);
    }

    private function rPostLogout(): Response
    {
        $this->session->destroy();
        return Response::redirect(self::SELF . '?r=login');
    }

    private function user(): ?string
    {
        $user = $this->session->get('user');
        if (!is_string($user)) {
            return null;
        }
        $t = $this->time();
        if ($t - (int) $this->session->get('seen', 0) > self::IDLE_TIMEOUT || $t - (int) $this->session->get('started', 0) > self::ABSOLUTE_TIMEOUT) {
            $this->session->destroy();
            return null;
        }
        $this->session->set('seen', $t);
        return $user;
    }

    private function role(): string
    {
        return (string) $this->session->get('role', 'editor');
    }

    private function csrf(): string
    {
        return (string) $this->session->get('csrf', '');
    }

    private function sameOrigin(): bool
    {
        $source = $this->req->header('origin') ?: $this->req->header('referer');
        if ($source === '') {
            return true;
        }
        $host = explode(':', $this->req->header('host'))[0];
        return strtolower((string) parse_url($source, PHP_URL_HOST)) === strtolower($host);
    }

    // ---------------------------------------------------------------- pages + editor

    private function rGetPages(): Response
    {
        $site = Site::load($this->app);
        $rows = [];
        $ids = [];
        foreach ($site->allPages() as $p) {
            $ids[$p->id] = true;
        }
        foreach (array_keys($ids) as $id) {
            $t = $site->translations($id);
            $rows[] = ['id' => $id, 'translations' => $t, 'missing' => array_values(array_diff($site->languages(), array_keys($t)))];
        }
        return $this->page('Pages', 'pages', ['rows' => $rows, 'site' => $site, 'warnings' => $site->warnings()]);
    }

    private function rGetEdit(): Response
    {
        $site = Site::load($this->app);
        $lang = (string) ($this->req->query['lang'] ?? $site->defaultLanguage());
        $id = (string) ($this->req->query['page'] ?? Page::HOME);
        $page = $site->page($id, $lang) ?? throw new LouException("Page '$id' does not exist in '$lang'");
        $token = (new Previews($this->app))->editor();
        return $this->page('Edit: ' . $page->title, 'edit', [
            'site'    => $site,
            'page'    => $page,
            'preview' => Previews::urlPrefix($token) . $site->path($id, $lang),
        ], 200, 'edit-layout');
    }

    /** JSON: page meta for the settings panel (when the editor navigates inside the preview). */
    private function rGetMeta(): Response
    {
        $site = Site::load($this->app);
        $page = $site->page((string) ($this->req->query['page'] ?? ''), (string) ($this->req->query['lang'] ?? ''))
            ?? throw new LouException('Unknown page');
        return Response::json(200, ['ok' => true, 'page' => $page->id, 'lang' => $page->lang, 'title' => $page->title,
            'description' => $page->description, 'menu_label' => $page->data['menu_label'] ?? '', 'slug' => $page->slug,
            'special' => in_array($page->id, [Page::HOME, Page::NOT_FOUND], true)]);
    }

    private function rPostSave(): Response
    {
        [$id, $lang] = $this->pageParams();
        $field = $this->req->input('field');
        (new ContentStore($this->app))->setField($id, $lang, $field, $this->req->input('type') ?: 'text', $this->req->input('value'));
        $this->db->audit('editor', 'save', "$id/$lang $field", $this->user());
        return $this->afterChange();
    }

    private function rPostImage(): Response
    {
        [$id, $lang] = $this->pageParams();
        $field = $this->req->input('field');
        $image = ['alt' => $this->req->input('alt')];
        $file = $this->req->files['file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || !$this->isUploaded((string) $file['tmp_name'])) {
                throw new LouException('Upload failed (the file may be too large for the server)');
            }
            $image += (new ImageProcessor((int) ($this->app->config['admin']['image_max_width'] ?? 1920)))
                ->process((string) $file['tmp_name'], (string) $file['name'], $this->app->siteDir);
        }
        $current = (new ContentStore($this->app))->read($id, $lang);
        if (!isset($image['src']) && !is_array(\Lou\Support\Arr::get($current['fields'] ?? [], $field))) {
            throw new LouException('Choose an image to upload');
        }
        (new ContentStore($this->app))->setImage($id, $lang, $field, $image + (isset($image['src']) ? [] : ['src' => (string) \Lou\Support\Arr::get($current['fields'], "$field.src")]));
        $this->db->audit('editor', 'image', "$id/$lang $field", $this->user());
        return $this->afterChange();
    }

    private function rPostMeta(): Response
    {
        [$id, $lang] = $this->pageParams();
        $meta = [];
        foreach (['title', 'description', 'menu_label', 'slug'] as $k) {
            if (array_key_exists($k, $this->req->post)) {
                $meta[$k] = $this->req->input($k);
            }
        }
        (new ContentStore($this->app))->setMeta($id, $lang, $meta, $this->role() === 'admin');
        $this->db->audit('editor', 'meta', "$id/$lang " . implode(',', array_keys($meta)), $this->user());
        $site = Site::load($this->app);
        return $this->afterChange(['path' => $site->path($id, $lang)]);
    }

    private function afterChange(array $extra = []): Response
    {
        (new Previews($this->app))->rebuildAll();
        return Response::json(200, ['ok' => true, 'unpublished' => $this->unpublished()] + $extra);
    }

    private function rPostPublish(): Response
    {
        $result = (new Builder($this->app))->build();
        $this->db->audit('editor', 'publish', $result['id'], $this->user());
        return $this->page('Published', 'published', ['result' => $result]);
    }

    private function rPostShare(): Response
    {
        $days = max(1, min(30, (int) ($this->req->input('days') ?: 7)));
        $token = (new Previews($this->app))->create('share', $days * 86400, $this->user());
        $this->db->audit('editor', 'share-preview', $token, $this->user());
        $base = rtrim((string) $this->app->config['base_url'], '/');
        return Response::json(200, ['ok' => true, 'url' => $base . Previews::urlPrefix($token) . '/', 'days' => $days]);
    }

    private function rPostRevoke(): Response
    {
        (new Previews($this->app))->revoke($this->req->input('token'));
        return Response::redirect(self::SELF . '?r=builds');
    }

    // ---------------------------------------------------------------- submissions

    private function rGetSubmissions(): Response
    {
        $form = (string) ($this->req->query['form'] ?? '');
        $where = $form !== '' ? 'WHERE form = ?' : '';
        $rows = $this->db->run("SELECT id, form, lang, status, created_at, data FROM submissions $where ORDER BY id DESC LIMIT 200", $form !== '' ? [$form] : [])->fetchAll();
        $forms = $this->db->run('SELECT form, COUNT(*) AS n FROM submissions GROUP BY form ORDER BY form')->fetchAll();
        return $this->page('Submissions', 'submissions', ['rows' => $rows, 'forms' => $forms, 'form' => $form]);
    }

    private function rGetSubmission(): Response
    {
        $row = $this->db->run('SELECT * FROM submissions WHERE id = ?', [(int) ($this->req->query['id'] ?? 0)])->fetch()
            ?: throw new LouException('Submission not found');
        return $this->page('Submission #' . $row['id'], 'submission', ['row' => $row]);
    }

    private function rGetExport(): Response
    {
        $form = (string) ($this->req->query['form'] ?? '');
        $rows = $this->db->run('SELECT id, form, lang, status, created_at, data FROM submissions' . ($form !== '' ? ' WHERE form = ?' : '') . ' ORDER BY id', $form !== '' ? [$form] : [])->fetchAll();
        $keys = [];
        foreach ($rows as $r) {
            $keys += array_flip(array_keys(json_decode($r['data'], true) ?: []));
        }
        $keys = array_keys($keys);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
        fputcsv($fh, ['id', 'form', 'lang', 'status', 'created_at', ...$keys]);
        foreach ($rows as $r) {
            $d = json_decode($r['data'], true) ?: [];
            $cells = array_map(fn ($k) => preg_match('/^[=+\-@\t\r]/', (string) ($d[$k] ?? '')) ? "'" . $d[$k] : (string) ($d[$k] ?? ''), $keys);
            fputcsv($fh, [$r['id'], $r['form'], $r['lang'], $r['status'], $r['created_at'], ...$cells]);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        $name = 'submissions' . ($form !== '' ? '-' . preg_replace('/[^a-z0-9-]/', '', $form) : '') . '-' . gmdate('Ymd') . '.csv';
        return new Response(200, $csv, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"$name\""]);
    }

    private function rPostDeleteSubmission(): Response
    {
        $id = (int) $this->req->input('id');
        $this->db->run('DELETE FROM submissions WHERE id = ?', [$id]);
        $this->db->audit('admin', 'delete-submission', (string) $id, $this->user());
        return Response::redirect(self::SELF . '?r=submissions');
    }

    private function rGetSubscribers(): Response
    {
        $rows = $this->db->run('SELECT * FROM subscribers ORDER BY id DESC LIMIT 500')->fetchAll();
        return $this->page('Subscribers', 'subscribers', ['rows' => $rows]);
    }

    // ---------------------------------------------------------------- builds, backups, redirects

    private function rGetBuilds(): Response
    {
        $builds = array_reverse(array_map('basename', glob($this->app->varDir . '/builds/before-*', GLOB_ONLYDIR) ?: []));
        $live = is_file($this->app->varDir . '/live.json') ? Fs::readJson($this->app->varDir . '/live.json') : null;
        $previews = array_filter((new Previews($this->app))->all(), fn ($p) => $p['kind'] === 'share');
        return $this->page('Builds', 'builds', ['builds' => $builds, 'live' => $live, 'previews' => $previews]);
    }

    private function rPostRollback(): Response
    {
        $name = $this->req->input('build');
        if (!preg_match('/^before-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $name) || !is_dir($this->app->varDir . "/builds/$name")) {
            throw new LouException('Unknown build');
        }
        $out = $this->app->outputDir();
        $tmp = dirname($out) . '/.lou-rollback-' . bin2hex(random_bytes(3));
        Fs::copyDir($this->app->varDir . "/builds/$name", $tmp);
        if (is_dir("$out/_lou/p")) {
            Fs::ensureDir("$tmp/_lou");
            rename("$out/_lou/p", "$tmp/_lou/p");
        }
        $old = dirname($out) . '/.lou-old-' . bin2hex(random_bytes(3));
        rename($out, $old);
        rename($tmp, $out);
        Fs::removeDir($old);
        @unlink($this->app->varDir . '/live.json');
        $this->db->audit('admin', 'rollback', $name, $this->user());
        return $this->page('Rolled back', 'message', ['message' => "The live site was restored to the build $name. "
            . 'Your current content is unchanged; publishing again will build from it.']);
    }

    private function rGetBackups(): Response
    {
        $files = array_reverse(array_map('basename', glob($this->app->varDir . '/backups/*.zip') ?: []));
        $sizes = [];
        foreach ($files as $f) {
            $sizes[$f] = filesize($this->app->varDir . "/backups/$f");
        }
        return $this->page('Backups', 'backups', ['files' => $files, 'sizes' => $sizes]);
    }

    private function rPostBackup(): Response
    {
        $file = (new Backup($this->app))->create(null, null, 30);
        $this->db->audit('admin', 'backup', basename($file), $this->user());
        return Response::redirect(self::SELF . '?r=backups');
    }

    private function rGetDownload(): Response
    {
        $name = basename((string) ($this->req->query['file'] ?? ''));
        $path = $this->app->varDir . "/backups/$name";
        if (!preg_match('/^[a-z0-9._-]+\.zip$/i', $name) || !is_file($path)) {
            throw new LouException('Unknown backup');
        }
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type' => 'application/zip', 'Content-Disposition' => "attachment; filename=\"$name\"",
        ]);
    }

    private function rPostRestore(): Response
    {
        $file = $this->req->files['backup'] ?? null;
        if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !$this->isUploaded((string) $file['tmp_name'])) {
            throw new LouException('Upload a backup .zip');
        }
        $password = $this->req->input('password') ?: null;
        $safety = (new Backup($this->app))->restore((string) $file['tmp_name'], $password, $this->req->input('content_only') === '');
        $this->db = new Database($this->app->databaseFile());
        $this->db->audit('admin', 'restore', basename($safety), $this->user());
        (new Previews($this->app))->rebuildAll();
        return $this->page('Restored', 'message', ['message' => 'The backup was restored. The previous state was saved as '
            . basename($safety) . '. Check the preview, then publish.']);
    }

    private function rGetRedirects(): Response
    {
        $file = $this->app->siteDir . '/redirects.json';
        $site = Site::load($this->app);
        return $this->page('Redirects', 'redirects', ['entries' => is_file($file) ? Fs::readJson($file) : [], 'site' => $site]);
    }

    private function rPostRedirect(): Response
    {
        $file = $this->app->siteDir . '/redirects.json';
        $entries = is_file($file) ? Fs::readJson($file) : [];
        $from = $this->req->input('from');
        $action = $this->req->input('action');
        $entries = array_values(array_filter($entries, fn ($e) => $e['from'] !== $from));
        if ($action !== 'delete') {
            if (!preg_match('#^/[^\s"]*$#', $from)) {
                throw new LouException("Redirect sources must start with '/'");
            }
            $entry = ['from' => $from];
            if ($action === '410') {
                $entry['status'] = 410;
            } elseif (preg_match('#^https?://#', $this->req->input('url'))) {
                $entry['to'] = $this->req->input('url');
            } else {
                [$pid, $plang] = array_pad(explode('|', $this->req->input('target'), 2), 2, '');
                $entry['to'] = ['page' => $pid, 'lang' => $plang];
            }
            $entries[] = $entry;
        }
        usort($entries, fn ($a, $b) => strcmp($a['from'], $b['from']));
        Fs::writeJson($file, $entries);
        $this->db->audit('admin', 'redirect', "$action $from", $this->user());
        return Response::redirect(self::SELF . '?r=redirects');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: string, 1: string} */
    private function pageParams(): array
    {
        return [$this->req->input('page'), $this->req->input('lang')];
    }

    private function isUploaded(string $tmp): bool
    {
        return PHP_SAPI === 'cli' ? is_file($tmp) : is_uploaded_file($tmp);
    }

    public function unpublished(): bool
    {
        $live = $this->app->varDir . '/live.json';
        return !is_file($live) || (Fs::readJson($live)['site_hash'] ?? '') !== Builder::siteHash($this->app);
    }

    private function deny(): Response
    {
        return $this->page('Not allowed', 'message', ['message' => 'You are not allowed to do that.', 'error' => true], 403);
    }

    private function time(): int
    {
        return $this->now ?? time();
    }

    private function page(string $title, string $view, array $vars, int $status = 200, string $layout = 'layout'): Response
    {
        $e = static fn (mixed $s): string => Html::escape((string) $s);
        $vars += [
            'title'       => $title,
            'user'        => $this->session->get('user'),
            'role'        => $this->role(),
            'csrf'        => $this->csrf(),
            'self'        => self::SELF,
            'route'       => (string) ($this->req->query['r'] ?? 'pages'),
            'siteName'    => $this->app->config['name'],
            'unpublished' => $this->session->get('user') ? $this->unpublished() : false,
        ];
        $render = static function (string $__file, array $__vars) use ($e): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        };
        $content = $render(__DIR__ . "/views/$view.php", $vars);
        $html = $render(__DIR__ . "/views/$layout.php", $vars + ['content' => $content]);
        return new Response($status, $html, [
            'Content-Type'            => 'text/html; charset=utf-8',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'",
        ]);
    }
}
