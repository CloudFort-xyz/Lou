<?php

declare(strict_types=1);

namespace Lou;

use Lou\Content\Page;
use Lou\Content\Site;
use Lou\Support\Arr;
use Lou\Support\Html;

/**
 * The $v helper available in every template. Output helpers escape by default.
 *
 * Templates run only at build time. Nothing here is executed per request.
 */
final class View
{
    public const MODE_LIVE = 'live';
    public const MODE_PREVIEW = 'preview'; // clean preview (shareable links)
    public const MODE_EDIT = 'edit';       // admin editor preview: adds data-lou-field markers

    private ?string $layout = null;
    private bool $needsFormsJs = false;
    private string $content = '';
    /** @var array<string, string> */
    private array $assetHashes = [];

    public function __construct(
        public readonly Site $site,
        public readonly Page $page,
        public readonly string $mode = self::MODE_LIVE,
    ) {
    }

    // ------------------------------------------------------------ rendering

    public function render(): string
    {
        $html = $this->renderFile($this->page->template, []);
        // a template may call $v->layout('x'); layouts may themselves use a layout
        $guard = 0;
        while ($this->layout !== null) {
            if (++$guard > 5) {
                throw new LouException('Layout nesting too deep');
            }
            $name = $this->layout;
            $this->layout = null;
            $this->content = $html;
            $html = $this->renderFile($name, []);
        }
        return $html;
    }

    /** Wrap this template's output in the named layout. */
    public function layout(string $name): void
    {
        $this->layout = $name;
    }

    /** The wrapped template's output, for use inside a layout. */
    public function content(): string
    {
        return $this->content;
    }

    /** @param array<string, mixed> $vars available in the partial as variables */
    public function partial(string $name, array $vars = []): string
    {
        return $this->renderFile('partials/' . $name, $vars);
    }

    private function renderFile(string $name, array $vars): string
    {
        if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)?$#', $name)) {
            throw new LouException("Invalid template name '$name'");
        }
        $file = $this->site->app->siteDir . "/templates/$name.php";
        if (!is_file($file)) {
            throw new LouException("Template not found: templates/$name.php");
        }
        $v = $this;
        $render = static function (string $__file, array $__vars) use ($v): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        return $render($file, $vars);
    }

    // ------------------------------------------------------------ page fields

    /** Raw field value (no escaping). Use for logic, not for output. */
    public function field(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->page->fields, $path, $default);
    }

    public function has(string $path): bool
    {
        $v = $this->field($path);
        return $v !== null && $v !== '' && $v !== [];
    }

    /** Escaped plain text. */
    public function text(string $path, string $default = ''): string
    {
        $v = $this->field($path, $default);
        return Html::escape(is_scalar($v) ? (string) $v : $default);
    }

    /** Sanitised rich text (allow-listed tags only). */
    public function html(string $path): string
    {
        $v = $this->field($path, '');
        return Html::sanitise(is_string($v) ? $v : '');
    }

    /** Unescaped output. Avoid. Flagged in reviews of LLM changes. */
    public function raw(string $path): string
    {
        $v = $this->field($path, '');
        return is_scalar($v) ? (string) $v : '';
    }

    /** Marks an element as inline-editable. Prints nothing in live builds. */
    /**
     * Marks an element as inline-editable. Prints nothing in live builds.
     * $type: 'text' (plain text), 'html' (rich text, use with $v->html()) or 'image' (set by $v->image()).
     */
    public function editable(string $path, string $type = 'text'): string
    {
        if (!in_array($type, ['text', 'html', 'image'], true)) {
            throw new LouException("Unknown editable type '$type'");
        }
        if ($this->mode !== self::MODE_EDIT) {
            return '';
        }
        return ' data-lou-field="' . Html::escape($path) . '"' . ($type !== 'text' ? ' data-lou-type="' . $type . '"' : '');
    }

    /**
     * Marks an element as one item of a list field ("features.$i"), so editors can duplicate,
     * delete and move it. Only items of lists that already exist in the content can be changed.
     * Prints nothing in live builds.
     */
    public function item(string $path): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*\.\d+$/', $path)) {
            throw new LouException("\$v->item('$path'): expected the path of a list item, e.g. \"features.\$i\"");
        }
        if ($this->mode !== self::MODE_EDIT) {
            return '';
        }
        return ' data-lou-item="' . Html::escape($path) . '"';
    }

    /** <img> from an image field ({src, alt}). */
    public function image(string $path, string $class = '', bool $lazy = true): string
    {
        $img = $this->field($path);
        if (!is_array($img) || empty($img['src'])) {
            return '';
        }
        $attrs = [
            'src' => $this->assetUrl((string) $img['src']),
            'alt' => (string) ($img['alt'] ?? ''),
        ];
        if (isset($img['width'], $img['height'])) {
            $attrs['width'] = (string) (int) $img['width'];
            $attrs['height'] = (string) (int) $img['height'];
        }
        if ($class !== '') {
            $attrs['class'] = $class;
        }
        if ($lazy) {
            $attrs['loading'] = 'lazy';
            $attrs['decoding'] = 'async';
        }
        $out = '<img';
        foreach ($attrs as $k => $val) {
            $out .= ' ' . $k . '="' . Html::escape($val) . '"';
        }
        return $out . $this->editable($path, 'image') . '>';
    }

    // ------------------------------------------------------------ forms

    /**
     * Hidden fields every Lou form needs (form id, language, token slot, honeypot).
     * Use inside <form method="post" action="/_lou/submit.php" data-lou-form>.
     */
    public function formFields(string $formId): string
    {
        if (!isset($this->site->app->config['forms'][$formId])) {
            throw new LouException("Template uses unknown form '$formId' (define it under 'forms' in site/config.php)");
        }
        $this->needsFormsJs = true;
        return '<input type="hidden" name="form" value="' . Html::escape($formId) . '">'
            . '<input type="hidden" name="lang" value="' . Html::escape($this->page->lang) . '">'
            . '<input type="hidden" name="_token" value="">'
            . '<div class="lou-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
    }

    /** Captcha widget for the form, if one is configured for it. Prints nothing otherwise. */
    public function captcha(string $formId): string
    {
        $type = $this->site->app->config['forms'][$formId]['captcha'] ?? null;
        if (!$type) {
            return '';
        }
        $key = (string) Arr::get($this->site->app->config, "captcha.$type.site_key", '');
        if ($key === '') {
            throw new LouException("Form '$formId' uses captcha '$type' but captcha.$type.site_key is not set in site/config.php");
        }
        $this->needsFormsJs = true;
        return match ($type) {
            'turnstile' => '<div class="cf-turnstile" data-sitekey="' . Html::escape($key) . '" data-language="' . Html::escape($this->page->lang) . '"></div>'
                . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>',
            'recaptcha' => '<input type="hidden" name="g-recaptcha-response" value="">'
                . '<script src="https://www.google.com/recaptcha/api.js?render=' . rawurlencode($key) . '" async defer></script>',
            default     => throw new LouException("Unknown captcha '$type'"),
        };
    }

    /** Attributes for the <form> tag: method, action and data hooks for forms.js. */
    public function formAttrs(string $formId): string
    {
        $attrs = ' method="post" action="/_lou/submit.php" data-lou-form';
        $type = $this->site->app->config['forms'][$formId]['captcha'] ?? null;
        if ($type === 'recaptcha') {
            $attrs .= ' data-lou-recaptcha="' . Html::escape((string) Arr::get($this->site->app->config, 'captcha.recaptcha.site_key', '')) . '"';
        }
        return $attrs;
    }

    /** Script tags needed by the page (only pages with forms load forms.js). Call at the end of the layout. */
    public function scripts(): string
    {
        return $this->needsFormsJs ? '<script src="/_lou/forms.js" defer></script>' : '';
    }

    // ------------------------------------------------------------ site-wide

    public function lang(): string
    {
        return $this->page->lang;
    }

    public function title(): string
    {
        return Html::escape($this->page->title);
    }

    public function description(): string
    {
        return Html::escape($this->page->description);
    }

    /** Escape any string. */
    public function e(mixed $s): string
    {
        return Html::escape((string) $s);
    }

    /** Global content for the current language (site/content/site/<lang>.json), escaped. */
    public function global(string $path, string $default = ''): string
    {
        $v = Arr::get($this->site->globals($this->page->lang), $path, $default);
        return Html::escape(is_scalar($v) ? (string) $v : $default);
    }

    /** UI string (site/content/strings/<lang>.json), escaped. {name} placeholders are replaced. */
    public function t(string $key, array $replace = []): string
    {
        $s = $this->site->string($this->page->lang, $key) ?? $key;
        foreach ($replace as $k => $val) {
            $s = str_replace('{' . $k . '}', (string) $val, $s);
        }
        return Html::escape($s);
    }

    /** URL of a page by id, in the current (or given) language. Fails the build if it doesn't exist. */
    public function url(string $pageId, ?string $lang = null): string
    {
        return Html::escape($this->site->url($pageId, $lang ?? $this->page->lang));
    }

    /** Absolute URL (needs base_url), for canonical/hreflang/og tags. */
    public function absoluteUrl(string $pageId, ?string $lang = null): string
    {
        $base = rtrim((string) $this->site->app->config['base_url'], '/');
        return Html::escape($base . $this->site->path($pageId, $lang ?? $this->page->lang));
    }

    /** URL of a file in site/assets with a content hash for cache busting. */
    public function asset(string $path): string
    {
        return Html::escape($this->assetUrl('/assets/' . ltrim($path, '/')));
    }

    private function assetUrl(string $url): string
    {
        if (!str_starts_with($url, '/assets/')) {
            return $url; // external or absolute URL
        }
        $prefix = $this->site->urlPrefix;
        if (!isset($this->assetHashes[$url])) {
            $file = $this->site->app->siteDir . $url;
            $this->assetHashes[$url] = is_file($file) ? substr(md5_file($file) ?: '', 0, 8) : '';
        }
        return $prefix . ($this->assetHashes[$url] !== '' ? $url . '?v=' . $this->assetHashes[$url] : $url);
    }

    /**
     * Menu items for the current language, with escaped label/url and an active flag.
     * Pages that don't exist in this language are skipped.
     *
     * @return list<array{label: string, url: string, active: bool, children: list<array>}>
     */
    public function menu(): array
    {
        $build = function (array $items) use (&$build): array {
            $out = [];
            foreach ($items as $item) {
                $page = $this->site->page($item['page'], $this->page->lang);
                if ($page === null) {
                    continue;
                }
                $out[] = [
                    'label'    => Html::escape($page->menuLabel),
                    'url'      => Html::escape($this->site->url($page->id, $page->lang)),
                    'active'   => $page->id === $this->page->id,
                    'children' => $build($item['children']),
                ];
            }
            return $out;
        };
        return $build($this->site->menu());
    }

    /**
     * Child pages of $parentId (default: this page) in the current language, for listings such as job ads.
     * Sorted by the page's "order" (lower first, default 0), then title. Label, url and description are
     * escaped; "fields" is the raw field data (escape it with $v->e() when printing).
     *
     * @return list<array{id: string, title: string, label: string, url: string, description: string, fields: array<string, mixed>}>
     */
    public function children(?string $parentId = null): array
    {
        $parentId ??= $this->page->id;
        $lang = $this->page->lang;
        $pages = array_values(array_filter($this->site->allPages(),
            fn (Page $p) => $p->lang === $lang && $p->parent === $parentId));
        usort($pages, fn (Page $a, Page $b) => [(int) ($a->data['order'] ?? 0), $a->title] <=> [(int) ($b->data['order'] ?? 0), $b->title]);
        return array_map(fn (Page $p) => [
            'id'          => $p->id,
            'title'       => Html::escape($p->title),
            'label'       => Html::escape($p->menuLabel),
            'url'         => Html::escape($this->site->url($p->id, $lang)),
            'description' => Html::escape($p->description),
            'fields'      => $p->fields,
        ], $pages);
    }

    /**
     * This page in every language it exists in (for the language switcher and hreflang).
     *
     * @return list<array{lang: string, url: string, absolute: string, current: bool}>
     */
    public function alternates(): array
    {
        $base = rtrim((string) $this->site->app->config['base_url'], '/');
        $out = [];
        foreach ($this->site->languages() as $lang) {
            if ($this->site->page($this->page->id, $lang) === null) {
                continue;
            }
            $out[] = [
                'lang'     => $lang,
                'url'      => Html::escape($this->site->url($this->page->id, $lang)),
                'absolute' => Html::escape($base . $this->site->path($this->page->id, $lang)),
                'current'  => $lang === $this->page->lang,
            ];
        }
        return $out;
    }

    /** <link rel=canonical> + hreflang alternates (needs base_url). */
    public function seoLinks(): string
    {
        if ($this->site->app->config['base_url'] === '' || $this->page->isSpecial()) {
            return '';
        }
        $out = '<link rel="canonical" href="' . $this->absoluteUrl($this->page->id) . '">' . "\n";
        $alts = $this->alternates();
        if (count($alts) > 1) {
            foreach ($alts as $a) {
                $out .= '<link rel="alternate" hreflang="' . $a['lang'] . '" href="' . $a['absolute'] . '">' . "\n";
            }
            $def = $this->site->defaultLanguage();
            if ($this->site->page($this->page->id, $def) !== null) {
                $out .= '<link rel="alternate" hreflang="x-default" href="' . $this->absoluteUrl($this->page->id, $def) . '">' . "\n";
            }
        }
        return $out;
    }
}
