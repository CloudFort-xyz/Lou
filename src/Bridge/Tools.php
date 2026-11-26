<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\Admin\ImageProcessor;
use Lou\Admin\Previews;
use Lou\App;
use Lou\Content\Site;
use Lou\Content\Workspace;
use Lou\Db\Database;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * The operations an assistant can perform, shared by the MCP endpoint and the REST fallback.
 * Every write goes to staging; nothing reaches the live site until a human approves it in the admin.
 */
final class Tools
{
    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly string $caller,
        private readonly string $siteUrl = '',
    ) {
    }

    /** @return list<array{name: string, description: string, inputSchema: array}> */
    public static function definitions(): array
    {
        $path = ['type' => 'string', 'description' => 'Path relative to site/, e.g. templates/page.php or content/pages/about/en.json'];
        return [
            self::def('describe_site', 'Start here. Explains how this site is built (content model, templates, helpers, rules), lists languages, pages, templates and forms, and shows your draft and what is on staging.', []),
            self::def('list_site_files', 'List files of the site as you see it (your draft, which includes everything on staging).', ['prefix' => ['type' => 'string', 'description' => 'Optional folder, e.g. templates or content/pages/about']]),
            self::def('read_site_file', 'Read a text file of the site as you see it (your draft).', ['path' => $path], ['path']),
            self::def('write_site_file', 'Create or replace a text file in your draft. PHP is checked against the template rules; JSON must be valid.', ['path' => $path, 'content' => ['type' => 'string', 'description' => 'Full new file content (UTF-8)']], ['path', 'content']),
            self::def('delete_site_file', 'Delete a file from your draft (e.g. a page language file or an unused template).', ['path' => $path], ['path']),
            self::def('upload_site_image', 'Add an image to your draft (it is re-encoded to WebP and resized). Returns the src to use in content JSON.', ['filename' => ['type' => 'string'], 'data_base64' => ['type' => 'string', 'description' => 'JPEG, PNG, GIF or WebP, base64-encoded']], ['filename', 'data_base64']),
            self::def('build_preview', 'Build your draft into a private preview. Returns the preview URL, build warnings, and the URLs that will redirect once published. Fix any errors it reports.', []),
            self::def('get_changes', 'Show the changes in your draft: changed files with diffs, validation problems and conflicts with staging.', []),
            self::def('submit_for_review', 'Move the changes in your draft to staging, where a person reviews and publishes them. Include a short summary for the reviewer.', ['summary' => ['type' => 'string']], ['summary']),
            self::def('discard_changes', 'Throw away your whole draft.', []),
            self::def('list_site_updates', 'Read-only. The site\'s publish history, newest first: when each update went live, who submitted it (person or assistant) with their summary, who published it, and which files changed. Also lists other live-site events (rollbacks, rebuilds, Lou updates) and what is waiting for review.', [
                'limit' => ['type' => 'integer', 'description' => 'How many updates to return (1-' . SiteUpdates::MAX_LIMIT . ', default 20)'],
                'since' => ['type' => 'string', 'description' => 'Only updates published at or after this time, e.g. 2026-11-20, 2026-11-20T08:00:00Z or "7 days ago"'],
                'path'  => ['type' => 'string', 'description' => 'Only updates that changed this file (relative to site/), e.g. content/pages/about/en.json'],
            ]),
            self::def('get_site_update', 'Read-only. Details of one published update from list_site_updates: who submitted and published it, and each changed file in plain words (page title and language, the fields that changed, old → new). Set diff to true for line diffs.', [
                'id'   => ['type' => 'string', 'description' => 'Update id from list_site_updates, e.g. 20261126-101500-123456, or "latest"'],
                'path' => ['type' => 'string', 'description' => 'Optional: only this file of the update'],
                'diff' => ['type' => 'boolean', 'description' => 'Include unified line diffs (old → new). Default false.'],
            ], ['id']),
        ];
    }

    private static function def(string $name, string $desc, array $props, array $required = []): array
    {
        return ['name' => $name, 'description' => $desc, 'inputSchema' => ['type' => 'object', 'properties' => (object) $props, 'required' => $required, 'additionalProperties' => false]]
            + (in_array($name, self::READ_ONLY, true) ? ['annotations' => ['readOnlyHint' => true]] : []);
    }

    /**
     * Earlier tool names, still accepted but not listed. The file tools were renamed because Copilot Studio
     * routes a tool called read_file to its own built-in file reader ("Error: No file path provided").
     */
    public const OLD_NAMES = [
        'list_files' => 'list_site_files', 'read_file' => 'read_site_file', 'write_file' => 'write_site_file',
        'delete_file' => 'delete_site_file', 'upload_image' => 'upload_site_image',
    ];

    /** The current name of a tool, or null if there is no such tool. */
    public static function resolve(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $name = self::OLD_NAMES[$name] ?? $name;
        return in_array($name, array_column(self::definitions(), 'name'), true) ? $name : null;
    }

    /** Other names clients use for our parameters (lower-case, without _ or -). */
    private const ALIASES = [
        'path' => ['filepath', 'file', 'filename', 'pathname', 'relativepath', 'name'],
        'content' => ['contents', 'text', 'body', 'data', 'filecontent', 'newcontent'],
        'data_base64' => ['base64', 'database64', 'image', 'imagebase64', 'data', 'content'],
        'filename' => ['name', 'file', 'path'],
        'prefix' => ['folder', 'dir', 'directory', 'path'],
        'summary' => ['message', 'description', 'note'],
        'id' => ['updateid', 'entryid', 'historyid', 'publishid', 'update', 'entry', 'version'],
        'limit' => ['count', 'max', 'maxresults', 'top', 'n', 'number'],
        'since' => ['from', 'after', 'start', 'startdate', 'fromdate', 'date'],
        'diff' => ['diffs', 'includediff', 'includediffs', 'withdiff', 'showdiff', 'details'],
    ];

    /**
     * Map what a client sent onto the tool's declared parameters. Clients differ: Copilot Studio and some
     * OpenAPI connectors wrap arguments ({"body": {...}}), send them as a JSON string, or rename them
     * (filePath, file_path, Path). Declared names always win; unknown keys are dropped.
     *
     * @return array<string, mixed>
     */
    public static function normaliseArguments(string $tool, mixed $args): array
    {
        $tool = self::resolve($tool) ?? $tool;
        if (is_string($args)) {
            $args = trim($args) === '' ? [] : json_decode($args, true);
        }
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            throw new LouException('The arguments must be a JSON object, e.g. {"path": "content/pages/about/en.json"}. Received: ' . self::shape($args));
        }
        $declared = [];
        foreach (self::definitions() as $d) {
            if ($d['name'] === $tool) {
                $declared = array_keys((array) $d['inputSchema']['properties']);
            }
        }
        if ($declared === []) {
            return [];
        }
        // one wrapper level, e.g. {"arguments": {...}}, {"input": "{...}"}, {"body": {...}}
        if (count($args) === 1 && !array_intersect(array_keys($args), $declared)) {
            $inner = reset($args);
            if (is_string($inner) && str_starts_with(ltrim($inner), '{')) {
                $inner = json_decode($inner, true);
            }
            if (is_array($inner) && $inner !== [] && !array_is_list($inner)) {
                $args = $inner;
            }
        }
        $flat = static fn (string $k): string => strtolower(str_replace(['_', '-', ' '], '', $k));
        $byFlat = [];
        foreach ($args as $k => $v) {
            $byFlat[$flat((string) $k)] ??= $v;
        }
        $out = [];
        foreach ($declared as $p) {
            if (array_key_exists($p, $args)) {
                $out[$p] = $args[$p];
                continue;
            }
            foreach ([$flat($p), ...(self::ALIASES[$p] ?? [])] as $alt) {
                // an alias may not steal a value that belongs to another declared parameter
                if (array_key_exists($alt, $byFlat) && ($alt === $flat($p) || !in_array($alt, array_map($flat, $declared), true))) {
                    $out[$p] = $byFlat[$alt];
                    break;
                }
            }
        }
        foreach ($out as $p => $v) {
            if (is_int($v) || is_float($v)) {
                $out[$p] = (string) $v;
            } elseif (is_array($v) && array_keys($v) === ['value'] && is_string($v['value'])) {
                $out[$p] = $v['value']; // {"value": "..."}
            } elseif (is_array($v) && $p === 'content') {
                // a JSON file sent as an object instead of a string
                $out[$p] = json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
        }
        return $out;
    }

    /** Key names and value types of what a client sent, for error messages (never the values). */
    public static function shape(mixed $args): string
    {
        if (!is_array($args)) {
            return get_debug_type($args);
        }
        if ($args === []) {
            return '{} (no arguments)';
        }
        $parts = [];
        foreach (array_slice($args, 0, 12, true) as $k => $v) {
            $parts[] = json_encode((string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ': '
                . (is_array($v) ? (array_is_list($v) ? 'array' : 'object {' . implode(', ', array_slice(array_map('strval', array_keys($v)), 0, 8)) . '}') : get_debug_type($v));
        }
        return '{' . implode(', ', $parts) . (count($args) > 12 ? ', …' : '') . '}';
    }

    /** @return string|array result for the assistant */
    public function call(string $name, mixed $rawArgs): string|array
    {
        $name = self::resolve($name) ?? throw new LouException("Unknown tool '$name'");
        $args = self::normaliseArguments($name, $rawArgs);
        $staging = Workspace::draft($this->app, $this->caller);
        $staging->rebase(); // see what others submitted or published meanwhile
        $str = static fn (string $k): string => is_string($args[$k] ?? null) ? $args[$k]
            : throw new LouException("Missing argument '$k' for $name. Send it as {\"$k\": \"...\"} in the tool arguments. Received: " . self::shape($rawArgs));

        $result = match ($name) {
            'describe_site'     => $this->describe($staging),
            'list_site_files'        => $staging->listFiles(is_string($args['prefix'] ?? null) ? $args['prefix'] : ''),
            'read_site_file'         => $staging->read($str('path')),
            'write_site_file'        => $this->write($staging, $str('path'), $str('content')),
            'delete_site_file'       => $this->delete($staging, $str('path')),
            'upload_site_image'      => $this->upload($staging, $str('filename'), $str('data_base64')),
            'build_preview'     => $this->preview($staging),
            'get_changes'       => $this->changes($staging),
            'submit_for_review' => $this->submit($staging, $str('summary')),
            'discard_changes'   => $this->discard($staging),
            'list_site_updates' => (new SiteUpdates($this->app, $this->db))->list(SiteUpdates::limit($args['limit'] ?? null),
                SiteUpdates::since(is_string($args['since'] ?? null) ? $args['since'] : null), self::optional($args, 'path')),
            'get_site_update'   => (new SiteUpdates($this->app, $this->db))->get($str('id'), self::optional($args, 'path'), self::flag($args['diff'] ?? false)),
            default             => throw new LouException("Unknown tool '$name'"),
        };
        if (!in_array($name, self::READ_ONLY, true)) {
            $this->db->audit('llm', $name, isset($args['path']) ? (string) $args['path'] : null, $this->caller);
        }
        return $result;
    }

    private const READ_ONLY = ['describe_site', 'list_site_files', 'read_site_file', 'get_changes', 'list_site_updates', 'get_site_update'];

    private static function optional(array $args, string $key): ?string
    {
        return is_string($args[$key] ?? null) && trim($args[$key]) !== '' ? $args[$key] : null;
    }

    /** true, "true", "yes", "1", 1 → true; anything else → false */
    private static function flag(mixed $v): bool
    {
        return $v === true || (is_string($v) && in_array(strtolower(trim($v)), ['true', 'yes', '1', 'on', 'y'], true));
    }

    private function write(Workspace $s, string $path, string $content): string
    {
        $s->write($path, $content);
        return "Saved $path to your draft. Call build_preview to check the result.";
    }

    private function delete(Workspace $s, string $path): string
    {
        $s->delete($path);
        return "Deleted $path from your draft.";
    }

    private function upload(Workspace $s, string $filename, string $b64): array
    {
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            throw new LouException('data_base64 is not valid base64');
        }
        $s->open();
        $tmp = $this->app->varDir . '/.upload-' . bin2hex(random_bytes(4));
        Fs::write($tmp, $bytes);
        try {
            $img = (new ImageProcessor((int) ($this->app->config['admin']['image_max_width'] ?? 1920)))->process($tmp, $filename, $s->siteDir());
            $s->touch();
        } finally {
            @unlink($tmp);
        }
        return $img + ['note' => 'Use {"src": "' . $img['src'] . '", "alt": "…", "width": ' . $img['width'] . ', "height": ' . $img['height'] . '} in an image field.'];
    }

    private function preview(Workspace $s): array
    {
        $problems = $s->validate();
        if ($problems) {
            return ['ok' => false, 'problems' => $problems];
        }
        $p = (new Previews($this->app))->stable('draft', 'draft:' . $this->caller, $this->caller);
        $url = ($this->siteUrl ?: $this->app->siteUrl()) . Previews::urlPrefix($this->app, $p['token']) . '/';
        if ($s->exists()) {
            $s->recordPreview($url, $p['result']);
        }
        return [
            'ok'        => true,
            'url'       => $url,
            'pages'     => $p['result']['pages'],
            'warnings'  => $p['result']['warnings'],
            'redirects' => $p['result']['redirects'],
            'note'      => 'Share this link with the user. Nothing is live until your draft is submitted to staging and published.',
        ];
    }

    private function changes(Workspace $s): array
    {
        if (!$s->hasChanges()) {
            return ['changes' => false, 'note' => 'Your draft has no changes. write_site_file starts one.'];
        }
        return ['changes' => true, 'files' => $s->changes(), 'diffs' => $s->diffs(),
            'problems' => $s->validate(), 'conflicts' => $s->conflicts()];
    }

    private function submit(Workspace $s, string $summary): array
    {
        if (!$s->hasChanges()) {
            throw new LouException('There are no changes to submit');
        }
        if (trim($summary) === '') {
            throw new LouException('Write a short summary for the reviewer');
        }
        $conflicts = $s->conflicts();
        if ($conflicts) {
            throw new LouException('Someone else changed these files on staging since you started. Read them again (read_site_file), '
                . 'redo your change on top of their version with write_site_file, then submit again:', $conflicts);
        }
        $files = $s->apply();
        $staging = Workspace::staging($this->app);
        $staging->addSubmission($this->caller, $summary, $files);
        $previews = new Previews($this->app);
        $previews->revokeSource('draft:' . $this->caller);
        $base = $this->siteUrl ?: $this->app->siteUrl();
        $p = $previews->staging($base, $this->caller);   // the reviewer finds the preview ready
        $previews->rebuild(null, $p ? [$p['token']] : []);
        return ['ok' => true, 'submitted' => $files, 'review_url' => $base . $this->app->endpoint('admin.php') . '?r=review',
            'preview_url' => $p ? rtrim($base, '/') . Previews::urlPrefix($this->app, $p['token']) . '/' : null,
            'note' => 'Your changes are on staging. Ask the user to open the review page, check the preview and publish.'];
    }

    private function discard(Workspace $s): string
    {
        $s->discard();
        (new Previews($this->app))->revokeSource('draft:' . $this->caller);
        return 'Your draft was discarded.';
    }

    private function describe(Workspace $s): array
    {
        $app = $s->app();
        try {
            $site = Site::load($app);
            $pages = [];
            foreach ($site->allPages() as $p) {
                $pages[] = ['id' => $p->id, 'lang' => $p->lang, 'url' => $site->path($p->id, $p->lang), 'template' => $p->template, 'title' => $p->title];
            }
            $siteError = null;
        } catch (LouException $e) {
            $pages = [];
            $siteError = $e->getMessage();
        }
        $templates = array_map(fn ($f) => substr($f, 10), array_values(array_filter($s->listFiles('templates'), fn ($f) => str_ends_with($f, '.php'))));
        return [
            'site'          => $app->config['name'],
            'languages'     => $app->config['languages'],
            'default_language' => $app->config['default_language'],
            'pages'         => $pages,
            'templates'     => $templates,
            'forms'         => array_map(fn ($f) => array_keys((array) ($f['fields'] ?? [])), (array) ($app->config['forms'] ?? [])),
            'site_error'    => $siteError,
            'draft'         => $s->hasChanges() ? ['files' => $s->changes(), 'conflicts' => $s->conflicts()] : null,
            'staging'       => Workspace::staging($this->app)->exists() ? Workspace::staging($this->app)->meta()['submissions'] : null,
            'guide'         => self::GUIDE,
        ];
    }

    private const GUIDE = <<<'MD'
        # How this Lou site works

        Everything you change goes to your own draft (a private copy of the site). Others' drafts are separate.
        Workflow: describe_site → read_site_file → write_site_file (repeat) → build_preview (fix problems) → share the preview URL
        with the user → submit_for_review with a short summary. That puts your changes on staging, where a person
        reviews everything and publishes it. Nothing goes live before that.

        To see what changed on the site before (when, what, who submitted and who published it): list_site_updates,
        then get_site_update with an id (diff: true for line diffs). Both are read-only.

        ## Files (paths relative to site/)
        - content/pages/<page-id>/<lang>.json : one file per page and language. Keys: slug, title, menu_label, description,
          template, parent (page id, optional), sitemap (bool), fields (object: the editable content).
          The page id (folder name) is stable; slugs are per language. "home" is the root, "not-found" the 404 page.
          Every page should exist in every language.
        - content/site/<lang>.json   : global content (company name, address, footer…)
        - content/strings/<lang>.json: UI strings used by templates ($v->t('key')) and forms
        - menu.json                  : menu structure as page ids, e.g. ["home", {"page": "services", "children": ["consulting"]}]
        - templates/*.php, templates/partials/*.php : plain PHP templates
        - assets/                    : css, js, images, fonts (referenced via $v->asset('css/site.css'))
        - config.php                 : site settings and form definitions (no secrets)
        - redirects.json             : managed automatically when slugs change; add manual entries if needed

        ## Template helper $v (output is escaped unless noted)
        $v->layout('layout'), $v->content(), $v->partial('name', [vars])
        $v->text('hero.title'), $v->html('body') (sanitised rich text), $v->field('features', []) (raw value for loops), $v->has('x')
        $v->image('hero.image', class: 'x', lazy: true), $v->editable('path', 'text'|'html') (marks inline-editable elements)
        $v->url('page-id'), $v->asset('path'), $v->t('key', ['name' => 'value']), $v->global('company')
        $v->menu() (items: label, url, active, children), $v->children() (child pages: id, title, url, description, raw fields; sorted by "order"), $v->alternates() (language switcher), $v->seoLinks(), $v->scripts()
        $v->lang(), $v->title(), $v->description(), $v->e($string)
        Forms: <form<?= $v->formAttrs('contact') ?>><?= $v->formFields('contact') ?> … <?= $v->captcha('contact') ?></form>

        ## Rules
        - Templates may only use $v, plain variables, loops/conditions and simple string/array functions. No I/O, no superglobals,
          no include/eval/new/static calls. write_site_file rejects anything else.
        - Mark every client-editable text with $v->editable(...) so clients can change it in the admin themselves.
        - Link pages with $v->url('page-id'), never hard-coded paths. Put user-facing text in content or strings files,
          in every language.
        - Keep pages fast and accessible: semantic HTML, alt texts, no external scripts or fonts unless asked.
        MD;
}
