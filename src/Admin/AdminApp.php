<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\App;
use Lou\Env;
use Lou\Integrations;
use Lou\Mail\MailSettings;
use Lou\Mail\Message;
use Lou\Backup\Backup;
use Lou\Bridge\Tokens;
use Lou\Build\Builder;
use Lou\Build\Output;
use Lou\Content\ChangeList;
use Lou\Content\ContentStore;
use Lou\Content\History;
use Lou\Content\Page;
use Lou\Content\Site;
use Lou\Content\Workspace;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\Http\Response;
use Lou\LouException;
use Lou\Setup\DesignImport;
use Lou\Setup\Updater;
use Lou\Support\Fs;
use Lou\Support\Git;
use Lou\Support\Html;

/**
 * The admin at /_lou/admin.php. Routes by ?r=…; GET renders pages, POST changes things
 * (always with a CSRF token and a same-origin check).
 */
final class AdminApp
{
    /**
     * The admin's own policy: it frames preview pages from this origin. stubs/_lou/.htaccess repeats it,
     * because the site-wide policy set there would otherwise win (or be combined) and block the frame.
     */
    public const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'self'";

    private const IDLE_TIMEOUT = 7200;
    private const ABSOLUTE_TIMEOUT = 43200;
    private const SELF = '/_lou/admin.php';

    /** Routes and the minimum role they need (null = public). */
    private const ROUTES = [
        'GET login'           => null,
        'POST login'          => null,
        'POST logout'         => 'editor',
        'GET dashboard'       => 'editor',
        'GET pages'           => 'editor',
        'GET edit'            => 'editor',
        'GET meta'            => 'editor',
        'POST save'           => 'editor',
        'POST image'          => 'editor',
        'POST meta'           => 'editor',
        'POST item'           => 'editor',
        'POST undo'           => 'editor',
        'POST redo'           => 'editor',
        'POST page-duplicate' => 'editor',
        'POST page-delete'    => 'editor',
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
        'POST backup-delete'  => 'admin',
        'POST restore'        => 'admin',
        'GET redirects'       => 'admin',
        'POST redirect'       => 'admin',
        'POST revoke'         => 'admin',
        'POST submit'         => 'editor',
        'POST revert'         => 'editor',
        'GET review'          => 'editor',
        'POST review-preview' => 'editor',
        'POST reject'         => 'editor',
        'POST staging-revert' => 'editor',
        'GET history'         => 'editor',
        'GET history-entry'   => 'editor',
        'POST history-restore' => 'editor',
        'GET assistants'      => 'admin',
        'POST token-add'      => 'admin',
        'POST token-revoke'   => 'admin',
        'GET settings'        => 'admin',
        'POST settings'       => 'admin',
        'POST mail-settings'  => 'admin',
        'POST mail-test'      => 'admin',
        'GET updates'         => 'admin',
        'POST update-check'   => 'admin',
        'POST update'         => 'admin',
        'POST update-upload'  => 'admin',
        'POST update-unsigned' => 'admin',
        'POST rebuild'        => 'admin',
        'POST design-upload'  => 'admin',
    ];

    private Request $req;
    private ?Integrations $integrations = null;
    private ?Updater $updater = null;

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
        $route = (string) ($req->query['r'] ?? 'dashboard');
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

    // ---------------------------------------------------------------- dashboard

    private function rGetDashboard(): Response
    {
        $site = Site::load($this->app);
        $ids = [];
        foreach ($site->allPages() as $p) {
            $ids[$p->id] = true;
        }
        $staging = Workspace::staging($this->app);
        $since = gmdate('Y-m-d', $this->time() - 7 * 86400);
        $live = is_file($this->app->varDir . '/live.json') ? Fs::readJson($this->app->varDir . '/live.json') : null;
        return $this->page('Dashboard', 'dashboard', [
            'site'        => $site,
            'pageCount'   => count($ids),
            'submissions' => (int) $this->db->run('SELECT COUNT(*) FROM submissions')->fetchColumn(),
            'recentCount' => (int) $this->db->run('SELECT COUNT(*) FROM submissions WHERE created_at >= ?', [$since])->fetchColumn(),
            'latest'      => $this->db->run('SELECT id, form, lang, status, created_at, data FROM submissions ORDER BY id DESC LIMIT 5')->fetchAll(),
            'subscribers' => (int) $this->db->run('SELECT COUNT(*) FROM subscribers')->fetchColumn(),
            'stagingOpen' => $staging->exists(),
            'stagingSubs' => $staging->exists() ? count($staging->meta()['submissions']) : 0,
            'history'     => array_slice((new History($this->app))->all(), 0, 5),
            'live'        => $live,
        ]);
    }

    // ---------------------------------------------------------------- pages + editor

    private function rGetPages(): Response
    {
        $this->draft()->rebase();
        $site = Site::load($this->draft()->app());
        $rows = [];
        $ids = [];
        foreach ($site->allPages() as $p) {
            $ids[$p->id] = true;
        }
        foreach (array_keys($ids) as $id) {
            $t = $site->translations($id);
            $rows[] = ['id' => $id, 'translations' => $t, 'missing' => array_values(array_diff($site->languages(), array_keys($t)))];
        }
        $store = new ContentStore($this->draft()->app());
        foreach ($rows as &$row) {
            $row['repeatable'] = $store->isRepeatable($row['id']);
        }
        unset($row);
        return $this->page('Pages', 'pages', ['rows' => $rows, 'site' => $site, 'warnings' => $site->warnings()]);
    }

    private function rGetEdit(): Response
    {
        $this->draft()->rebase();
        $site = Site::load($this->draft()->app());
        $lang = (string) ($this->req->query['lang'] ?? $site->defaultLanguage());
        $id = (string) ($this->req->query['page'] ?? Page::HOME);
        $page = $site->page($id, $lang) ?? throw new LouException("Page '$id' does not exist in '$lang'");
        $token = (new Previews($this->app))->editor($this->owner(), true);
        return $this->page('Edit: ' . $page->title, 'edit', [
            'site'    => $site,
            'page'    => $page,
            'preview' => Previews::urlPrefix($token) . $site->path($id, $lang),
            'history' => (new EditHistory($this->app, $this->draft()))->state($id),
        ], 200, 'edit-layout');
    }

    /** JSON: page meta for the settings panel (when the editor navigates inside the preview). */
    private function rGetMeta(): Response
    {
        $site = Site::load($this->draft()->app());
        $page = $site->page((string) ($this->req->query['page'] ?? ''), (string) ($this->req->query['lang'] ?? ''))
            ?? throw new LouException('Unknown page');
        return Response::json(200, ['ok' => true, 'page' => $page->id, 'lang' => $page->lang, 'title' => $page->title,
            'description' => $page->description, 'menu_label' => $page->data['menu_label'] ?? '', 'slug' => $page->slug,
            'special' => in_array($page->id, [Page::HOME, Page::NOT_FOUND], true),
            'history' => (new EditHistory($this->app, $this->draft()))->state($page->id)]);
    }

    private function rPostSave(): Response
    {
        [$id, $lang] = $this->pageParams();
        $field = $this->req->input('field');
        $this->inDraft(fn (ContentStore $c) => $c->setField($id, $lang, $field, $this->req->input('type') ?: 'text', $this->req->input('value')),
            [$id, $lang, 'Text edit']);
        $this->db->audit('editor', 'save', "$id/$lang $field", $this->user());
        return $this->afterChange(['field' => $field], $id);
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
                ->process((string) $file['tmp_name'], (string) $file['name'], $this->draftDir());
        }
        $this->inDraft(function (ContentStore $c) use ($id, $lang, $field, $image): void {
            $current = $c->read($id, $lang);
            if (!isset($image['src']) && !is_array(\Lou\Support\Arr::get($current['fields'] ?? [], $field))) {
                throw new LouException('Choose an image to upload');
            }
            $c->setImage($id, $lang, $field, $image + (isset($image['src']) ? [] : ['src' => (string) \Lou\Support\Arr::get($current['fields'], "$field.src")]));
        }, [$id, $lang, isset($image['src']) ? 'Image change' : 'Image description']);
        $this->db->audit('editor', 'image', "$id/$lang $field", $this->user());
        return $this->afterChange([], $id);
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
        $this->inDraft(fn (ContentStore $c) => $c->setMeta($id, $lang, $meta, $this->role() === 'admin'), [$id, $lang, 'Page settings']);
        $this->db->audit('editor', 'meta', "$id/$lang " . implode(',', array_keys($meta)), $this->user());
        $site = Site::load($this->draft()->app());
        return $this->afterChange(['path' => $site->path($id, $lang)], $id);
    }

    /** Duplicate, delete or move one item of a list field (data-lou-item in the editor preview). */
    private function rPostItem(): Response
    {
        [$id, $lang] = $this->pageParams();
        $item = $this->req->input('item');
        $action = $this->req->input('action');
        $labels = ['duplicate' => 'Duplicate', 'delete' => 'Delete', 'up' => 'Move', 'down' => 'Move'];
        $result = ['index' => 0, 'skipped' => []];
        $this->inDraft(function (ContentStore $c) use ($id, $lang, $item, $action, &$result): void {
            $result = $c->itemAction($id, $lang, $item, $action);
        }, [$id, $lang, $labels[$action] ?? 'Change']);
        $this->db->audit('editor', 'item-' . $action, "$id/$lang $item", $this->user());
        $list = substr($item, 0, (int) strrpos($item, '.'));
        return $this->afterChange(['item' => $list . '.' . $result['index'], 'action' => $action, 'skipped' => $result['skipped']], $id);
    }

    private function rPostUndo(): Response
    {
        return $this->undoRedo(true);
    }

    private function rPostRedo(): Response
    {
        return $this->undoRedo(false);
    }

    private function undoRedo(bool $undo): Response
    {
        [$id] = $this->pageParams();
        $h = new EditHistory($this->app, $this->draft());
        $this->draft()->rebase();
        $done = $undo ? $h->undo($id) : $h->redo($id);
        $this->db->audit('editor', $undo ? 'undo' : 'redo', "$id/{$done['lang']} {$done['label']}", $this->user());
        return $this->afterChange(['done' => $done], $id);
    }

    /** Copy a repeatable page (e.g. a job ad) in the draft and open the copy in the editor. */
    private function rPostPageDuplicate(): Response
    {
        $id = $this->req->input('page');
        $new = '';
        $this->inDraft(function (ContentStore $c) use ($id, &$new): void {
            $new = $c->duplicatePage($id);
        });
        $this->db->audit('editor', 'duplicate', "$id → $new", $this->user());
        (new Previews($this->app))->rebuild('draft:' . $this->owner());
        return Response::redirect(self::SELF . '?r=edit&page=' . rawurlencode($new) . '&lang=' . rawurlencode((string) $this->app->config['default_language']));
    }

    /** Delete a repeatable page in the draft; it goes live through review and publish like any edit. */
    private function rPostPageDelete(): Response
    {
        $id = $this->req->input('page');
        $this->inDraft(fn (ContentStore $c) => $c->deletePage($id));
        $this->db->audit('editor', 'delete', $id, $this->user());
        (new Previews($this->app))->rebuild('draft:' . $this->owner());
        return $this->done('pages', 'The page was deleted in your draft. It disappears from the site when your changes are published.');
    }

    private function afterChange(array $extra = [], ?string $page = null): Response
    {
        (new Previews($this->app))->rebuild('draft:' . $this->owner());
        if ($page !== null) {
            $extra['history'] = (new EditHistory($this->app, $this->draft()))->state($page);
        }
        return Response::json(200, ['ok' => true, 'draft' => $this->draft()->hasChanges()] + $extra);
    }

    // ---------------------------------------------------------------- drafts, staging, publishing

    private function owner(): string
    {
        return 'user:' . $this->user();
    }

    private function draft(): Workspace
    {
        return Workspace::draft($this->app, $this->owner());
    }

    /** The draft's site dir, opening the draft first. */
    private function draftDir(): string
    {
        $d = $this->draft();
        $d->open();
        return $d->siteDir();
    }

    /**
     * Run a ContentStore write against the user's draft.
     * $undo: [page id, lang, label] records the change for the editor's undo/redo.
     */
    private function inDraft(callable $fn, ?array $undo = null): void
    {
        $d = $this->draft();
        $d->open();
        $history = $undo ? new EditHistory($this->app, $d) : null;
        $before = $history?->snapshot((string) $undo[0]) ?? [];
        try {
            $fn(new ContentStore($d->app()));
            $d->touch();
            $history?->record((string) $undo[0], (string) $undo[1], (string) $undo[2], $before, $history->snapshot((string) $undo[0]));
        } finally {
            if (!$d->hasChanges()) {
                $d->discard();
            }
        }
    }

    /** Draft → staging. */
    private function rPostSubmit(): Response
    {
        $d = $this->draft();
        $d->rebase();
        if (!$d->hasChanges()) {
            throw new LouException('Your draft has no changes to submit.');
        }
        $conflicts = $d->conflicts();
        if ($conflicts && $this->req->input('force') !== '1') {
            return $this->page('Conflict', 'conflict', ['conflicts' => $conflicts, 'summary' => $this->req->input('summary')], 409);
        }
        $files = $this->submitDraft($d, $this->req->input('summary'), $this->req->input('force') === '1', $ready);
        return $this->done('review', 'Submitted ' . self::plural(count($files), 'change') . ' to staging.'
            . ($ready ? ' The preview is ready.' : ''));
    }

    /**
     * Draft → staging, then build the staging preview straight away so the reviewer finds it ready, and refresh
     * the other previews (drafts show staging). $ready: whether the preview could be built.
     * @return list<string> the submitted files
     */
    private function submitDraft(Workspace $d, string $summary, bool $force, ?bool &$ready = null): array
    {
        $files = $d->apply($force);
        Workspace::staging($this->app)->addSubmission($this->owner(), $summary, $files);
        $previews = new Previews($this->app);
        $p = $previews->staging($this->app->siteUrl($this->req), $this->user());
        $previews->rebuild(null, $p ? [$p['token']] : []);
        $ready = $p !== null;
        $this->db->audit('editor', 'submit', implode(', ', $files), $this->user());
        return $files;
    }

    /** Throw away (some of) my draft: the given files, or all of it. */
    private function rPostRevert(): Response
    {
        $files = array_filter((array) ($this->req->post['files'] ?? []), 'is_string');
        $files ? $this->draft()->revert($files) : $this->draft()->discard();
        (new Previews($this->app))->rebuild('draft:' . $this->owner());
        $this->db->audit('editor', 'revert', $files ? implode(', ', $files) : 'all', $this->user());
        return $this->done('review', $files ? 'Undid ' . self::plural(count($files), 'change') . ' in your draft.' : 'Your draft was discarded.');
    }

    private function rGetReview(): Response
    {
        $s = Workspace::staging($this->app);
        $mine = $this->draft();
        $mine->rebase();
        $drafts = [];
        foreach (Workspace::allDrafts($this->app) as $d) {
            if ($d->owner !== $this->owner() && $d->hasChanges()) {
                $drafts[] = ['owner' => $d->owner, 'files' => count($d->changedFiles()), 'updated' => $d->meta()['updated']];
            }
        }
        $vars = ['open' => $s->exists(), 'drafts' => $drafts, 'live' => is_file($this->app->varDir . '/live.json') ? Fs::readJson($this->app->varDir . '/live.json') : null,
            'mine' => $mine->hasChanges() ? ['items' => ChangeList::of($mine, $md = $mine->diffs()), 'diffs' => $md, 'conflicts' => $mine->conflicts()] : null];
        if ($s->exists()) {
            $diffs = $s->diffs();
            $vars += ['meta' => $s->meta(), 'diffs' => $diffs, 'items' => ChangeList::of($s, $diffs), 'problems' => $s->validate(),
                'conflicts' => $s->conflicts(), 'preview' => $s->lastPreview()];
        }
        return $this->page('Review', 'review', $vars);
    }

    private function rPostReviewPreview(): Response
    {
        $s = Workspace::staging($this->app);
        $problems = $s->validate();
        if ($problems) {
            throw new LouException('Staging has problems:', $problems);
        }
        $p = (new Previews($this->app))->stable('staging', 'staging', $this->user());
        $s->recordPreview($this->app->siteUrl($this->req) . Previews::urlPrefix($p['token']) . '/', $p['result']);
        return $this->done('review', 'The preview is up to date.');
    }

    /** Staging → site/ → live build. Without staging, just builds site/ (e.g. after redirect changes). */
    private function rPostPublish(): Response
    {
        $s = Workspace::staging($this->app);
        $applied = null;
        $safety = null;
        $meta = null;
        if ($s->exists()) {
            $meta = $s->meta();
            if ($this->req->input('rev') !== (string) $meta['rev']) {
                throw new LouException('Staging changed while you were reviewing it. Review it again.');
            }
            $safety = basename((new Backup($this->app))->create(null, null, 30));
            $history = new History($this->app);
            $entry = $history->record($s->changes(), $s->siteDir(), (string) $this->user(), $meta['submissions']);
            try {
                $applied = $s->apply($this->req->input('force') === '1');
                DesignImport::confirm($this->app);
            } catch (LouException $e) {
                if ($entry) {
                    Fs::removeDir($this->app->varDir . "/history/$entry");
                }
                throw $e;
            }
        }
        $result = (new Builder($this->app))->build();
        $previews = new Previews($this->app);
        $previews->revokeSource('staging');
        $previews->rebuild();
        $this->db->audit('editor', 'publish', $result['id'] . ($applied ? ': ' . implode(', ', $applied) : ''), $this->user());

        $git = null;
        if ($applied && !empty($this->app->config['git']['commit']) && Git::available($this->app->root)) {
            $lines = array_map(fn ($sub) => '- ' . ($sub['summary'] !== '' ? $sub['summary'] : count($sub['files']) . ' files') . ' (' . preg_replace('/^(user|llm):/', '', $sub['by']) . ')', $meta['submissions']);
            $subject = count($meta['submissions']) === 1 && $meta['submissions'][0]['summary'] !== ''
                ? strtok($meta['submissions'][0]['summary'], "\n") : 'Publish ' . count($applied) . ' changed files';
            try {
                $hash = (new Git($this->app->root))->commit($subject . "\n\n" . implode("\n", $lines) . "\n\nPublished by {$this->user()} in Lou.", (string) $this->user());
                $git = $hash ? "Committed to Git as $hash." : null;
            } catch (LouException $e) {
                $result['warnings'][] = 'The Git commit failed: ' . $e->getMessage();
            }
        }
        return $this->page('Published', 'published', ['result' => $result, 'applied' => $applied, 'git' => $git, 'safety' => $safety]);
    }

    private function rPostShare(): Response
    {
        $live = $this->req->input('live') === '1';
        $days = $live ? 1 : max(1, min(30, (int) ($this->req->input('days') ?: 7)));
        $token = (new Previews($this->app))->create($live ? 'live' : 'share', 'draft:' . $this->owner(), $days * 86400, $this->user());
        $this->db->audit('editor', $live ? 'live-preview' : 'share-preview', $token, $this->user());
        $base = $this->app->siteUrl($this->req);
        $path = $this->req->input('path');
        $path = preg_match('#^/[a-z0-9/\-]*$#', $path) ? $path : '/';
        return Response::json(200, ['ok' => true, 'url' => $base . Previews::urlPrefix($token) . $path, 'days' => $days, 'live' => $live]);
    }

    private function rPostRevoke(): Response
    {
        (new Previews($this->app))->revoke($this->req->input('token'));
        return $this->done('builds', 'The preview link was revoked. It stops working right away.');
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
        return $this->done('submissions', 'The submission was deleted.');
    }

    private function rGetSubscribers(): Response
    {
        $rows = $this->db->run('SELECT * FROM subscribers ORDER BY id DESC LIMIT 500')->fetchAll();
        return $this->page('Subscribers', 'subscribers', ['rows' => $rows]);
    }

    // ---------------------------------------------------------------- builds, backups, redirects

    private function rGetSettings(array $extra = []): Response
    {
        $env = Env::load($this->app);
        $smtp = (array) $env->get('smtp', []);
        $mail = (array) ($this->app->config['mail'] ?? []);
        return $this->page('Settings', 'settings', $extra + [
            'current'    => (string) $this->app->config['base_url'],
            'configured' => $this->app->baseUrlConfigured(),
            'suggested'  => $this->req->origin(),
            'saved'      => isset($this->req->query['saved']),
            'mailSaved'  => isset($this->req->query['mail-saved']),
            'mail'       => [
                'transport' => (string) ($mail['transport'] ?? 'smtp'),
                'from'      => (string) ($mail['from'] ?? ''),
                'from_name' => (string) ($mail['from_name'] ?? ''),
            ],
            // never the password itself: only whether one is stored
            'smtp'       => [
                'host'   => (string) ($smtp['host'] ?? ''),
                'port'   => (int) ($smtp['port'] ?? 587),
                'user'   => (string) ($smtp['user'] ?? ''),
                'secure' => (string) ($smtp['secure'] ?? 'tls'),
                'hasPass' => (string) ($smtp['pass'] ?? '') !== '',
            ],
            'smtpSource' => isset(Env::adminSecrets($this->app)['smtp']) ? 'admin' : (isset($smtp['host']) ? 'env' : null),
            'testResult' => null,
        ]);
    }

    /** Mail sender (var/settings.json) and SMTP server (var/secrets.json, 0600). An empty password keeps the stored one. */
    private function rPostMailSettings(): Response
    {
        $in = MailSettings::fromInput(fn (string $k): string => $this->req->input($k), (array) Env::load($this->app)->get('smtp', []));
        if ($in['smtp'] !== null) {
            Env::storeAdminSecret($this->app, 'smtp', $in['smtp']);
        }
        $transport = $in['mail']['transport'];
        $host = $in['smtp']['host'] ?? '';
        $settings = $this->app->settings();
        $settings['mail'] = $in['mail'];
        Fs::writeJson($this->app->varDir . '/settings.json', $settings);
        $this->db->audit('admin', 'settings', "mail $transport" . ($host !== '' ? " via $host" : ''), $this->user());
        return Response::redirect(self::SELF . '?r=settings&mail-saved=1');
    }

    /** Send a test email with the saved settings and show what happened. */
    private function rPostMailTest(): Response
    {
        $to = trim($this->req->input('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new LouException('Enter the address the test email should go to');
        }
        $app = new App($this->app->root);
        $result = ['ok' => true, 'message' => "Test email sent to $to. Check the inbox (and the spam folder)."];
        try {
            $integrations = $this->integrations ?? new Integrations($app, Env::load($app));
            if (($app->config['mail']['transport'] ?? 'smtp') === 'log') {
                $result['message'] = "Emails are set to 'write to a log file', so the test went to var/mail.log, not to $to.";
            }
            $integrations->mailer()->send(new Message([$to], '[' . $app->config['name'] . '] Lou test email',
                "This is a test email from the Lou admin of {$app->config['name']}.\n\nIf you can read this, form emails will arrive too.\n\nSent by {$this->user()} at " . gmdate('Y-m-d H:i') . " UTC."));
        } catch (LouException $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        $this->db->audit('admin', 'mail-test', ($result['ok'] ? 'ok ' : 'failed ') . $to, $this->user());
        return $this->rGetSettings(['testResult' => $result, 'testTo' => $to]);
    }

    /** For tests: use these integrations (and their mailer) for the test email. */
    public function useIntegrations(Integrations $integrations): void
    {
        $this->integrations = $integrations;
    }

    private function rPostSettings(): Response
    {
        $file = $this->app->varDir . '/settings.json';
        $settings = $this->app->settings();
        $settings['base_url'] = App::normalizeBaseUrl($this->req->input('base_url'));
        Fs::writeJson($file, $settings);
        $this->db->audit('admin', 'settings', 'base_url ' . $settings['base_url'], $this->user());
        // the sitemap, hreflang and canonical links are built from base_url
        (new Builder(new App($this->app->root)))->build();
        (new Previews($this->app))->rebuild();
        return Response::redirect(self::SELF . '?r=settings&saved=1');
    }

    // ---------------------------------------------------------------- updates

    private function updater(): Updater
    {
        return $this->updater ?? new Updater($this->app);
    }

    /** For tests: use this updater (fake HTTP, test signing key). */
    public function useUpdater(Updater $updater): void
    {
        $this->updater = $updater;
    }

    private function rGetUpdates(array $extra = []): Response
    {
        $u = $this->updater();
        $updated = is_file($this->app->varDir . '/updated.json') ? Fs::readJson($this->app->varDir . '/updated.json') : null;
        return $this->page('Updates', 'updates', $extra + [
            'version'    => App::VERSION,
            'blocked'    => $u->blocked(),
            'signed'     => $u->signaturesChecked(),
            'check'      => $u->lastCheck(),
            'updated'    => $updated,
            'needsBuild' => Updater::needsRebuild($this->app),
            'maxUpload'  => (string) ini_get('upload_max_filesize'),
            'unsignedAllowed' => $u->signaturesChecked() && $u->unsignedAllowed(),
            'checkError' => null,
        ]);
    }

    private function rPostUpdateCheck(): Response
    {
        try {
            $this->updater()->check();
        } catch (LouException $e) {
            return $this->rGetUpdates(['checkError' => $e->getMessage()]);
        }
        return Response::redirect(self::SELF . '?r=updates');
    }

    /** Download the release found by the last check and swap in its code. The rebuild runs on the next request. */
    private function rPostUpdate(): Response
    {
        $u = $this->updater();
        $zip = $u->download();
        try {
            $r = $u->apply($zip);
        } finally {
            @unlink($zip);
        }
        $this->db->audit('admin', 'update', "{$r['from']} → {$r['to']}" . ($r['signed'] ? '' : ' (unsigned)'), $this->user());
        return Response::redirect(self::SELF . '?r=updates');
    }

    private function rPostUpdateUpload(): Response
    {
        $file = $this->req->files['release'] ?? null;
        if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !$this->isUploaded((string) $file['tmp_name'])) {
            throw new LouException('Upload a Lou release .zip (lou-<version>.zip)');
        }
        $u = $this->updater();
        $info = $u->inspect((string) $file['tmp_name']);
        if (in_array($info['signature'], ['missing', 'invalid'], true)) {
            return $this->unsignedUpload($u, $file, $info);
        }
        $r = $u->apply((string) $file['tmp_name'], $this->req->input('allow_older') === '1');
        $this->db->audit('admin', 'update', "{$r['from']} → {$r['to']} (uploaded" . ($r['signed'] ? ')' : ', unsigned)'), $this->user());
        return Response::redirect(self::SELF . '?r=updates');
    }

    /**
     * A release zip without a valid signature. Refused unless .env.php sets allow_unsigned_updates (FTP/SSH
     * access, so a stolen admin login alone can't install code). Otherwise it's parked for an hour and a
     * security warning asks the admin to type the version to confirm.
     */
    private function unsignedUpload(Updater $u, array $file, array $info): Response
    {
        $why = $info['signature'] === 'invalid'
            ? 'This zip’s signature does not match Lou’s release key (it may have been changed), so it was not installed.'
            : 'This zip is not signed with Lou’s release key, so it was not installed.';
        if (!$u->unsignedAllowed()) {
            throw new LouException($why . " If you trust it (for example a test build you made), someone with FTP or SSH access can add 'allow_unsigned_updates' => true to .env.php. You will then be asked to confirm with a security warning.");
        }
        $dir = $this->app->varDir . '/updates';
        foreach (glob("$dir/unsigned-*.zip") ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(16));
        Fs::ensureDir($dir);
        if (!copy((string) $file['tmp_name'], "$dir/unsigned-$token.zip")) {
            throw new LouException('Cannot store the uploaded zip');
        }
        $this->session->set('unsignedUpload', $token);
        return $this->page('Unsigned release', 'update-unsigned', [
            'version'    => $info['version'],
            'signature'  => $info['signature'],
            'current'    => App::VERSION,
            'name'       => (string) ($file['name'] ?? 'release.zip'),
            'sha256'     => (string) hash_file('sha256', "$dir/unsigned-$token.zip"),
            'token'      => $token,
            'allowOlder' => $this->req->input('allow_older') === '1',
        ]);
    }

    private function rPostUpdateUnsigned(): Response
    {
        $token = $this->req->input('token');
        $zip = $this->app->varDir . "/updates/unsigned-$token.zip";
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !hash_equals((string) $this->session->get('unsignedUpload', ''), $token)
            || !is_file($zip) || filemtime($zip) < time() - 3600) {
            throw new LouException('This upload has expired. Upload the zip again.');
        }
        $u = $this->updater();
        $info = $u->inspect($zip);
        if (trim($this->req->input('confirm')) !== $info['version']) {
            throw new LouException("To install it, type the version exactly: {$info['version']}");
        }
        try {
            $r = $u->apply($zip, $this->req->input('allow_older') === '1', true);
        } finally {
            @unlink($zip);
            $this->session->set('unsignedUpload', null);
        }
        $this->db->audit('admin', 'update', "{$r['from']} → {$r['to']} (uploaded, UNSIGNED {$info['signature']}, confirmed)", $this->user());
        return Response::redirect(self::SELF . '?r=updates');
    }

    /** A client site zip: its templates and assets go into the uploader's draft, then through Review. */
    private function rPostDesignUpload(): Response
    {
        $file = $this->req->files['site'] ?? null;
        if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !$this->isUploaded((string) $file['tmp_name'])) {
            throw new LouException('Upload a site .zip');
        }
        $name = mb_substr(basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))), 0, 100) ?: 'a zip';
        $draft = $this->draft();
        $draft->rebase();
        $hadDraft = $draft->hasChanges();
        $r = (new DesignImport($this->app))->toDraft((string) $file['tmp_name'], $draft);
        $this->db->audit('admin', 'design-upload', "$name: " . count($r['written']) . ' written, ' . count($r['deleted']) . ' deleted, '
            . count($r['skipped']) . ' skipped, ' . count($r['rejected']) . ' rejected', $this->user());
        $r += ['name' => $name, 'submitted' => false, 'held' => null, 'ready' => false];
        if (!$r['written'] && !$r['deleted']) {
            return $this->page('Site design', 'design-import', $r);
        }
        // Straight to staging when asked and the draft held nothing else, so the reviewer gets a ready preview.
        // Otherwise it waits in the draft, and the result page says why.
        $r['held'] = match (true) {
            $this->req->input('review') !== '1' => null,
            $hadDraft                           => 'draft',
            (bool) $draft->conflicts()          => 'conflicts',
            default                             => false,
        };
        if ($r['held'] === false) {
            try {
                $this->submitDraft($draft, "Site design from $name", false, $ready);
                $r['submitted'] = true;
                $r['ready'] = (bool) $ready;
            } catch (LouException $e) {
                $r['held'] = 'problems';
                $r['problems'] = [$e->getMessage()];
            }
        }
        if (!$r['submitted']) {
            (new Previews($this->app))->rebuild('draft:' . $this->owner());
        } elseif (!$r['skipped'] && !$r['rejected']) {
            return $this->done('review', 'The new design from ' . $name . ' is on staging (' . self::plural(count($r['written']) + count($r['deleted']), 'change') . ').'
                . ($r['ready'] ? ' Check the preview, then publish.' : ''));
        }
        return $this->page('Site design', 'design-import', $r);
    }

    /** Rebuild the live site and previews with the code on disk (after an update). */
    private function rPostRebuild(): Response
    {
        $r = (new Builder(new App($this->app->root)))->build();
        (new Previews($this->app))->rebuildAll();
        $this->db->audit('admin', 'rebuild', $r['id'], $this->user());
        return $this->page('Rebuilt', 'message', ['message' => "The site was rebuilt with Lou " . App::VERSION . " ({$r['pages']} pages)."]);
    }

    private function rGetBuilds(): Response
    {
        $builds = array_reverse(array_map('basename', glob($this->app->varDir . '/builds/before-*', GLOB_ONLYDIR) ?: []));
        $live = is_file($this->app->varDir . '/live.json') ? Fs::readJson($this->app->varDir . '/live.json') : null;
        $previews = array_filter((new Previews($this->app))->all(), fn ($p) => in_array($p['kind'], ['share', 'live'], true));
        return $this->page('Builds', 'builds', ['builds' => $builds, 'live' => $live, 'previews' => $previews]);
    }

    private function rPostRollback(): Response
    {
        $name = $this->req->input('build');
        if (!preg_match('/^before-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $name) || !is_dir($this->app->varDir . "/builds/$name")) {
            throw new LouException('Unknown build');
        }
        $out = $this->app->outputDir();
        $output = new Output($this->app);
        $id = 'rollback-' . bin2hex(random_bytes(3));
        $tmp = $output->tempDir($out, $id, $output->mode());
        Fs::copyDir($this->app->varDir . "/builds/$name", $tmp);
        if (is_dir("$out/_lou/p")) {
            Fs::ensureDir("$tmp/_lou");
            rename("$out/_lou/p", "$tmp/_lou/p");
        }
        $old = $output->swap($tmp, $out, $id, $output->mode());
        if ($old !== null) {
            Fs::removeDir($old);
        }
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
        return $this->done('backups', 'Backup created: ' . basename($file) . '.');
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

    private function rPostBackupDelete(): Response
    {
        $name = basename($this->req->input('file'));
        $path = $this->app->varDir . "/backups/$name";
        if (!preg_match('/^[a-z0-9._-]+\.zip$/i', $name) || !is_file($path)) {
            throw new LouException('Unknown backup');
        }
        if (!unlink($path)) {
            throw new LouException("Could not delete $name. Check the file permissions of var/backups.");
        }
        $this->db->audit('admin', 'backup-delete', $name, $this->user());
        return $this->done('backups', "Backup $name deleted.");
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
        return $this->done('redirects', match ($action) {
            'delete' => "Redirect from $from deleted.",
            '410'    => "$from now answers 410 Gone.",
            default  => "Redirect from $from saved.",
        } . ' It goes live with the next publish.');
    }

    // ---------------------------------------------------------------- assistants

    private function rPostReject(): Response
    {
        $s = Workspace::staging($this->app);
        $files = $s->changedFiles();
        $s->discard();
        $previews = new Previews($this->app);
        $previews->revokeSource('staging');
        $previews->rebuild();
        $this->db->audit('editor', 'reject-staging', implode(', ', $files), $this->user());
        return $this->done('review', 'Everything on staging was discarded. The live site was not touched.');
    }

    /** Take single changes back off staging (they return to the live version); the rest stays for review. */
    private function rPostStagingRevert(): Response
    {
        $s = Workspace::staging($this->app);
        $files = array_values(array_intersect(array_filter((array) ($this->req->post['files'] ?? []), 'is_string'), $s->changedFiles()));
        if (!$files) {
            throw new LouException('That change is no longer on staging.');
        }
        $s->revert($files);
        $previews = new Previews($this->app);
        if (!$s->hasChanges()) {
            $s->discard();
            $previews->revokeSource('staging');
            $previews->rebuild();
        } else {
            $p = $previews->staging($this->app->siteUrl($this->req), $this->user());
            $previews->rebuild(null, $p ? [$p['token']] : []);
        }
        $this->db->audit('editor', 'unstage', implode(', ', $files), $this->user());
        return $this->done('review', 'Took ' . self::plural(count($files), 'change') . ' off staging. The live site was not touched.');
    }

    // ---------------------------------------------------------------- history

    private function rGetHistory(): Response
    {
        $h = new History($this->app);
        $path = (string) ($this->req->query['file'] ?? '');
        return $this->page('History', 'history', ['entries' => $h->all($path !== '' ? $path : null), 'file' => $path, 'enabled' => $h->enabled()]);
    }

    private function rGetHistoryEntry(): Response
    {
        $h = new History($this->app);
        $id = (string) ($this->req->query['id'] ?? '');
        return $this->page('History', 'history-entry', ['entry' => $h->get($id), 'diffs' => $h->diffs($id)]);
    }

    /** Put old (or new) versions from a history entry into my draft; they go live through the usual submit + publish. */
    private function rPostHistoryRestore(): Response
    {
        $files = array_values(array_filter((array) ($this->req->post['files'] ?? []), 'is_string'));
        $side = $this->req->input('side') === 'after' ? 'after' : 'before';
        $done = (new History($this->app))->restoreInto($this->draft(), $this->req->input('id'), $side, $files ?: null);
        (new Previews($this->app))->rebuild('draft:' . $this->owner());
        $this->db->audit('editor', 'history-restore', $this->req->input('id') . " $side: " . implode(', ', $done), $this->user());
        return $this->done('review', 'Put ' . self::plural(count($done), 'file') . ' into your draft. Submit it to make it live.');
    }

    private function rGetAssistants(): Response
    {
        // shown once: taken out of the session on the first page view after creating it
        $new = $this->session->get('new_token');
        if ($new !== null) {
            $this->session->set('new_token', null);
        }
        return $this->page('Assistants', 'assistants', ['tokens' => $this->tokens()->all(), 'newToken' => is_string($new) ? $new : null]);
    }

    private function rPostTokenAdd(): Response
    {
        $token = $this->tokens()->create($this->req->input('name'));
        $this->db->audit('admin', 'token-add', $this->req->input('name'), $this->user());
        $this->session->set('new_token', $token);
        return Response::redirect(self::SELF . '?r=assistants');
    }

    private function rPostTokenRevoke(): Response
    {
        $this->tokens()->revoke($this->req->input('id'));
        $this->db->audit('admin', 'token-revoke', $this->req->input('id'), $this->user());
        return $this->done('assistants', 'The token was revoked. The assistant using it has lost access.');
    }

    private function tokens(): Tokens
    {
        return new Tokens($this->app->varDir . '/tokens.json');
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

    /** Redirect to a page after a POST, with a one-time notice shown at the top of it. */
    private function done(string $route, string $message): Response
    {
        $this->session->set('flash', $message);
        return Response::redirect(self::SELF . '?r=' . $route);
    }

    private static function plural(int $n, string $word): string
    {
        return $n . ' ' . $word . ($n === 1 ? '' : 's');
    }

    /**
     * A timestamp as <time>: readable UTC in the HTML, and admin.js shows it in the browser's own time zone.
     * Takes an ISO date, a SQLite "Y-m-d H:i:s" (UTC) or a Unix time.
     */
    public static function stamp(string|int|null $at, bool $dateOnly = false): string
    {
        if ($at === null || $at === '') {
            return '';
        }
        try {
            $d = is_int($at) ? new \DateTimeImmutable("@$at") : new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return Html::escape((string) $at);
        }
        $d = $d->setTimezone(new \DateTimeZone('UTC'));
        return '<time datetime="' . $d->format('Y-m-d\\TH:i:s\\Z') . '"' . ($dateOnly ? ' data-date' : '') . '>'
            . $d->format($dateOnly ? 'Y-m-d' : 'Y-m-d H:i') . ($dateOnly ? '' : ' UTC') . '</time>';
    }

    private function deny(): Response
    {
        return $this->page('Not allowed', 'message', ['message' => 'You are not allowed to do that.', 'error' => true], 403);
    }

    private function time(): int
    {
        return $this->now ?? time();
    }

    private function takeFlash(): ?string
    {
        $flash = $this->session->get('flash');
        if ($flash !== null) {
            $this->session->set('flash', null);
        }
        return is_string($flash) ? $flash : null;
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
            'route'       => (string) ($this->req->query['r'] ?? 'dashboard'),
            'siteName'    => $this->app->config['name'],
            'baseUrl'     => $this->app->siteUrl($this->req),
            'baseUrlMissing' => !$this->app->baseUrlConfigured(),
            'unpublished' => $this->session->get('user') ? $this->unpublished() : false,
            'staged'      => $this->session->get('user') ? count(Workspace::staging($this->app)->changedFiles()) : 0,
            'drafted'     => $this->session->get('user') ? count($this->draft()->changedFiles()) : 0,
            'needsRebuild' => $this->session->get('user') ? Updater::needsRebuild($this->app) : false,
            'louVersion'  => App::VERSION,
            'flash'       => $this->takeFlash(),
            'time'        => self::stamp(...),
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
            'Content-Security-Policy' => self::CSP,
        ]);
    }
}
