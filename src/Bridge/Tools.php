<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\Admin\ImageProcessor;
use Lou\Admin\Previews;
use Lou\App;
use Lou\Content\Site;
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
    ) {
    }

    /** @return list<array{name: string, description: string, inputSchema: array}> */
    public static function definitions(): array
    {
        $path = ['type' => 'string', 'description' => 'Path relative to site/, e.g. templates/page.php or content/pages/about/en.json'];
        return [
            self::def('describe_site', 'Start here. Explains how this Lou site is built (content model, templates, helpers, rules), lists languages, pages, templates and forms, and shows the state of the current changeset.', []),
            self::def('list_files', 'List files of the site (the staged copy if a changeset is open).', ['prefix' => ['type' => 'string', 'description' => 'Optional folder, e.g. templates or content/pages/about']]),
            self::def('read_file', 'Read a text file of the site (the staged copy if a changeset is open).', ['path' => $path], ['path']),
            self::def('write_file', 'Create or replace a text file in the staged copy. Opens a changeset if needed. PHP is checked against the template rules; JSON must be valid.', ['path' => $path, 'content' => ['type' => 'string', 'description' => 'Full new file content (UTF-8)']], ['path', 'content']),
            self::def('delete_file', 'Delete a file from the staged copy (e.g. a page language file or an unused template).', ['path' => $path], ['path']),
            self::def('upload_image', 'Add an image to the staged copy (it is re-encoded to WebP and resized). Returns the src to use in content JSON.', ['filename' => ['type' => 'string'], 'data_base64' => ['type' => 'string', 'description' => 'JPEG, PNG, GIF or WebP, base64-encoded']], ['filename', 'data_base64']),
            self::def('build_preview', 'Build the staged site into a private preview. Returns the preview URL, build warnings, and the URLs that will redirect after approval. Fix any errors it reports.', []),
            self::def('get_changes', 'Show the changeset: changed files with diffs, validation problems and conflicts.', []),
            self::def('submit_for_review', 'Mark the changeset ready for a human to review and approve in the admin. Include a short summary for the reviewer.', ['summary' => ['type' => 'string']], ['summary']),
            self::def('discard_changes', 'Throw away the whole staged changeset.', []),
        ];
    }

    private static function def(string $name, string $desc, array $props, array $required = []): array
    {
        return ['name' => $name, 'description' => $desc, 'inputSchema' => ['type' => 'object', 'properties' => (object) $props, 'required' => $required, 'additionalProperties' => false]];
    }

    /** @return string|array result for the assistant */
    public function call(string $name, array $args): string|array
    {
        $staging = new Staging($this->app);
        $str = static fn (string $k): string => is_string($args[$k] ?? null) ? $args[$k] : throw new LouException("Missing argument '$k'");

        $result = match ($name) {
            'describe_site'     => $this->describe($staging),
            'list_files'        => $staging->listFiles(is_string($args['prefix'] ?? null) ? $args['prefix'] : ''),
            'read_file'         => $staging->read($str('path')),
            'write_file'        => $this->write($staging, $str('path'), $str('content')),
            'delete_file'       => $this->delete($staging, $str('path')),
            'upload_image'      => $this->upload($staging, $str('filename'), $str('data_base64')),
            'build_preview'     => $this->preview($staging),
            'get_changes'       => $this->changes($staging),
            'submit_for_review' => $this->submit($staging, $str('summary')),
            'discard_changes'   => $this->discard($staging),
            default             => throw new LouException("Unknown tool '$name'"),
        };
        if (!in_array($name, ['describe_site', 'list_files', 'read_file', 'get_changes'], true)) {
            $this->db->audit('llm', $name, isset($args['path']) ? (string) $args['path'] : null, $this->caller);
        }
        return $result;
    }

    private function write(Staging $s, string $path, string $content): string
    {
        $s->write($path, $content, $this->caller);
        return "Saved $path to staging. Call build_preview to check the result.";
    }

    private function delete(Staging $s, string $path): string
    {
        $s->delete($path, $this->caller);
        return "Deleted $path from staging.";
    }

    private function upload(Staging $s, string $filename, string $b64): array
    {
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            throw new LouException('data_base64 is not valid base64');
        }
        $s->open($this->caller);
        $tmp = $this->app->varDir . '/.upload-' . bin2hex(random_bytes(4));
        Fs::write($tmp, $bytes);
        try {
            $img = (new ImageProcessor((int) ($this->app->config['admin']['image_max_width'] ?? 1920)))->process($tmp, $filename, $s->siteDir());
        } finally {
            @unlink($tmp);
        }
        return $img + ['note' => 'Use {"src": "' . $img['src'] . '", "alt": "…", "width": ' . $img['width'] . ', "height": ' . $img['height'] . '} in an image field.'];
    }

    private function preview(Staging $s): array
    {
        $s->open($this->caller);
        $problems = $s->validate();
        if ($problems) {
            return ['ok' => false, 'problems' => $problems];
        }
        $p = (new Previews($this->app))->changeset($this->caller);
        $url = rtrim((string) $this->app->config['base_url'], '/') . Previews::urlPrefix($p['token']) . '/';
        $s->recordPreview($url, $p['result']);
        return [
            'ok'        => true,
            'url'       => $url,
            'pages'     => $p['result']['pages'],
            'warnings'  => $p['result']['warnings'],
            'redirects' => $p['result']['redirects'],
            'note'      => 'Share this link with the user. Nothing is live until they approve the changeset in the admin.',
        ];
    }

    private function changes(Staging $s): array
    {
        if (!$s->exists()) {
            return ['open' => false, 'note' => 'No changeset is open. write_file opens one.'];
        }
        return ['open' => true, 'changeset' => $s->meta(), 'files' => $s->changes(), 'diffs' => $s->diffs(),
            'problems' => $s->validate(), 'conflicts' => $s->conflicts()];
    }

    private function submit(Staging $s, string $summary): array
    {
        $c = $s->changes();
        if (!array_merge($c['added'], $c['modified'], $c['deleted'])) {
            throw new LouException('There are no changes to submit');
        }
        $problems = $s->validate();
        if ($problems) {
            throw new LouException('Fix these first:', $problems);
        }
        $s->submit($summary);
        $base = rtrim((string) $this->app->config['base_url'], '/');
        return ['ok' => true, 'review_url' => $base . '/_lou/admin.php?r=changes',
            'note' => 'Ask the user to open the review page, check the preview and approve.'];
    }

    private function discard(Staging $s): string
    {
        $s->discard();
        (new Previews($this->app))->revokeKind('changeset');
        return 'The changeset was discarded.';
    }

    private function describe(Staging $s): array
    {
        $app = $s->exists() ? $s->app() : $this->app;
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
            'changeset'     => $s->exists() ? $s->meta() + ['files' => $s->changes()] : null,
            'guide'         => self::GUIDE,
        ];
    }

    private const GUIDE = <<<'MD'
        # How this Lou site works

        Everything you change is staged. The user reviews a preview and approves it before anything goes live.
        Workflow: describe_site → read_file → write_file (repeat) → build_preview (fix problems) → share the preview URL
        with the user → submit_for_review with a short summary.

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
        $v->menu() (items: label, url, active, children), $v->alternates() (language switcher), $v->seoLinks(), $v->scripts()
        $v->lang(), $v->title(), $v->description(), $v->e($string)
        Forms: <form<?= $v->formAttrs('contact') ?>><?= $v->formFields('contact') ?> … <?= $v->captcha('contact') ?></form>

        ## Rules
        - Templates may only use $v, plain variables, loops/conditions and simple string/array functions. No I/O, no superglobals,
          no include/eval/new/static calls. write_file rejects anything else.
        - Mark every client-editable text with $v->editable(...) so clients can change it in the admin themselves.
        - Link pages with $v->url('page-id'), never hard-coded paths. Put user-facing text in content or strings files,
          in every language.
        - Keep pages fast and accessible: semantic HTML, alt texts, no external scripts or fonts unless asked.
        MD;
}
