<?php

declare(strict_types=1);

namespace Lou\Content;

use Lou\App;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * Loads and validates everything under site/content, and resolves page ids to URLs.
 */
final class Site
{
    private const ID_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var array<string, array<string, Page>> pages[id][lang] */
    private array $pages = [];
    /** @var array<string, array<string, mixed>> */
    private array $globals = [];
    /** @var array<string, array<string, string>> */
    private array $strings = [];
    /** @var list<array<string, mixed>> */
    private array $menu = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string, string> memoised urls, "id|lang" => url */
    private array $urls = [];

    private function __construct(public readonly App $app)
    {
    }

    public static function load(App $app): self
    {
        $site = new self($app);
        $site->loadPages();
        $site->loadGlobals();
        $site->validate();
        return $site;
    }

    // ---------------------------------------------------------------- accessors

    /** @return list<string> */
    public function languages(): array
    {
        return $this->app->config['languages'];
    }

    public function defaultLanguage(): string
    {
        return $this->app->config['default_language'];
    }

    public function page(string $id, string $lang): ?Page
    {
        return $this->pages[$id][$lang] ?? null;
    }

    /** @return list<Page> every page in every language */
    public function allPages(): array
    {
        $out = [];
        foreach ($this->pages as $byLang) {
            foreach ($this->languages() as $lang) {
                if (isset($byLang[$lang])) {
                    $out[] = $byLang[$lang];
                }
            }
        }
        return $out;
    }

    /** @return array<string, Page> the page in each language it exists in */
    public function translations(string $id): array
    {
        return $this->pages[$id] ?? [];
    }

    public function globals(string $lang): array
    {
        return $this->globals[$lang] ?? [];
    }

    public function string(string $lang, string $key): ?string
    {
        return $this->strings[$lang][$key] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function menu(): array
    {
        return $this->menu;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    // ---------------------------------------------------------------- urls

    public function languagePrefix(string $lang): string
    {
        $isDefault = $lang === $this->defaultLanguage();
        return ($isDefault && !$this->app->config['prefix_default']) ? '' : '/' . $lang;
    }

    /** Root-relative URL of a page, always ending in "/" (or "404.html" for not-found). */
    public function url(string $id, string $lang): string
    {
        $key = "$id|$lang";
        if (isset($this->urls[$key])) {
            return $this->urls[$key];
        }
        $page = $this->page($id, $lang)
            ?? throw new LouException("Link to unknown page '$id' in language '$lang'");

        $prefix = $this->languagePrefix($lang);
        if ($id === Page::HOME) {
            return $this->urls[$key] = $prefix . '/';
        }
        if ($id === Page::NOT_FOUND) {
            return $this->urls[$key] = $prefix . '/404.html';
        }

        $slugs = [];
        $seen = [];
        for ($p = $page; $p !== null; $p = $p->parent ? $this->page($p->parent, $lang) : null) {
            if (isset($seen[$p->id])) {
                throw new LouException("Parent cycle at page '{$p->id}'");
            }
            $seen[$p->id] = true;
            if ($p->parent !== null && $this->page($p->parent, $lang) === null) {
                throw new LouException("Page '{$p->id}' ($lang) has parent '{$p->parent}', which doesn't exist in $lang");
            }
            array_unshift($slugs, $p->slug);
        }
        return $this->urls[$key] = $prefix . '/' . implode('/', $slugs) . '/';
    }

    // ---------------------------------------------------------------- loading

    private function loadPages(): void
    {
        $dir = $this->app->siteDir . '/content/pages';
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $pageDir) {
            $id = basename($pageDir);
            if (!preg_match(self::ID_RE, $id)) {
                throw new LouException("Invalid page id '$id' (use lowercase letters, digits and dashes)");
            }
            foreach ($this->languages() as $lang) {
                $file = "$pageDir/$lang.json";
                if (is_file($file)) {
                    $this->pages[$id][$lang] = $this->makePage($id, $lang, Fs::readJson($file), $file);
                }
            }
            $missing = array_diff($this->languages(), array_keys($this->pages[$id] ?? []));
            if ($missing && isset($this->pages[$id])) {
                $this->warnings[] = "Page '$id' has no translation for: " . implode(', ', $missing);
            }
        }
        if (!isset($this->pages[Page::HOME][$this->defaultLanguage()])) {
            throw new LouException("Missing home page: site/content/pages/home/{$this->defaultLanguage()}.json");
        }
    }

    private function makePage(string $id, string $lang, array $d, string $file): Page
    {
        $slug = (string) ($d['slug'] ?? $id);
        $special = in_array($id, [Page::HOME, Page::NOT_FOUND], true);
        if (!$special && !preg_match(self::ID_RE, $slug)) {
            throw new LouException("Invalid slug '$slug' in $file (lowercase ASCII letters, digits and dashes)");
        }
        if (isset($d['fields']) && !is_array($d['fields'])) {
            throw new LouException("'fields' must be an object in $file");
        }
        $title = (string) ($d['title'] ?? $id);
        return new Page(
            id: $id,
            lang: $lang,
            slug: $slug,
            title: $title,
            menuLabel: (string) ($d['menu_label'] ?? $title),
            description: (string) ($d['description'] ?? ''),
            template: (string) ($d['template'] ?? 'page'),
            parent: isset($d['parent']) ? (string) $d['parent'] : null,
            fields: $d['fields'] ?? [],
            data: $d,
        );
    }

    private function loadGlobals(): void
    {
        $c = $this->app->siteDir . '/content';
        foreach ($this->languages() as $lang) {
            $this->globals[$lang] = is_file("$c/site/$lang.json") ? Fs::readJson("$c/site/$lang.json") : [];
            $this->strings[$lang] = is_file("$c/strings/$lang.json") ? Fs::readJson("$c/strings/$lang.json") : [];
        }
        $menuFile = $this->app->siteDir . '/menu.json';
        $this->menu = is_file($menuFile) ? self::normaliseMenu(Fs::readJson($menuFile)) : [];
    }

    /** Accepts ["about", {"page": "services", "children": [...]}] */
    private static function normaliseMenu(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $item = is_string($item) ? ['page' => $item] : $item;
            $item['children'] = self::normaliseMenu($item['children'] ?? []);
            $out[] = $item;
        }
        return $out;
    }

    // ---------------------------------------------------------------- validation

    private function validate(): void
    {
        $errors = [];
        $seen = [];
        foreach ($this->allPages() as $page) {
            try {
                $url = $this->url($page->id, $page->lang);
                if (isset($seen[$url])) {
                    $errors[] = "Duplicate URL $url: pages '{$seen[$url]}' and '{$page->id}'";
                }
                $seen[$url] = $page->id;
            } catch (LouException $e) {
                $errors[] = $e->getMessage();
            }
            $tpl = $this->app->siteDir . "/templates/{$page->template}.php";
            if (!preg_match('/^[a-z0-9_\-]+$/', $page->template) || !is_file($tpl)) {
                $errors[] = "Page '{$page->id}' ($page->lang) uses missing template '{$page->template}'";
            }
        }
        $walk = function (array $items) use (&$walk, &$errors): void {
            foreach ($items as $item) {
                if (!isset($this->pages[$item['page'] ?? ''])) {
                    $errors[] = "Menu links to unknown page '" . ($item['page'] ?? '?') . "'";
                }
                $walk($item['children']);
            }
        };
        $walk($this->menu);
        if ($errors) {
            throw new LouException('Site content is invalid:', $errors);
        }
    }
}
