<?php

declare(strict_types=1);

namespace Lou\Build;

use Lou\App;
use Lou\Content\Page;
use Lou\Content\Site;
use Lou\LouException;
use Lou\Support\Fs;
use Lou\Support\Html;
use Lou\View;

/**
 * Renders the whole site to static files and swaps the result in atomically.
 */
final class Builder
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param string      $mode   View::MODE_LIVE writes redirects + manifest and keeps build history;
     *                            View::MODE_PREVIEW only renders (with editor markers).
     * @param string|null $outDir defaults to the configured output dir
     * @return array{id: string, output: string, pages: int, warnings: list<string>, redirects: array}
     */
    public function build(string $mode = View::MODE_LIVE, ?string $outDir = null): array
    {
        $live = $mode === View::MODE_LIVE;
        $out = rtrim($outDir ?? $this->app->outputDir(), '/');
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $site = Site::load($this->app);
        $warnings = $site->warnings();

        // 1. URL manifest + redirects (validated before anything is written)
        $manifest = [];
        foreach ($site->allPages() as $page) {
            if (!$page->isSpecial()) {
                $manifest["{$page->id}|{$page->lang}"] = $site->url($page->id, $page->lang);
            }
        }
        $redirectsFile = $this->app->siteDir . '/redirects.json';
        $entries = is_file($redirectsFile) ? Fs::readJson($redirectsFile) : [];
        $manifestFile = $this->app->varDir . '/manifest.json';
        $prev = is_file($manifestFile) ? Fs::readJson($manifestFile) : [];
        [$entries, $report] = Redirects::update($prev, $manifest, $entries, $site->defaultLanguage());
        $rules = Redirects::resolve($site, $entries, $manifest);
        foreach ($report['gone'] as $g) {
            $warnings[] = "Removed page '{$g['page']}' ({$g['lang']}): {$g['from']} now redirects to the home page. "
                . 'Change the target or set status 410 in site/redirects.json.';
        }

        // 2. render into a temp dir next to the output (same filesystem → atomic rename)
        Fs::ensureDir(dirname($out));
        $tmp = dirname($out) . '/.lou-tmp-' . $id;
        Fs::ensureDir($tmp);
        try {
            $count = 0;
            foreach ($site->allPages() as $page) {
                $html = (new View($site, $page, $mode))->render();
                $url = $site->url($page->id, $page->lang);
                $file = str_ends_with($url, '.html') ? $tmp . $url : $tmp . $url . 'index.html';
                Fs::write($file, $html);
                $count++;
            }

            if (is_dir($this->app->siteDir . '/assets')) {
                Fs::copyDir($this->app->siteDir . '/assets', $tmp . '/assets');
            }
            Fs::copyDir($this->app->stubsDir, $tmp);
            foreach (glob($tmp . '/_lou/*.php') ?: [] as $stub) {
                // endpoint stubs require Lou core from outside the web root
                Fs::write($stub, str_replace('__LOU_ROOT__', var_export($this->app->root, true), (string) file_get_contents($stub)));
            }
            $this->writeHtaccess($tmp, $site, $rules, $live);
            foreach ($rules as $r) {
                $target = $tmp . rtrim($r['from'], '/') . '/index.html';
                if ($r['to'] !== null && !str_ends_with($r['from'], '.html') && !is_file($target)) {
                    Fs::write($target, Redirects::fallbackHtml($r['to']));
                }
            }
            if ($live) {
                $this->writeSitemap($tmp, $site, $warnings);
            } else {
                Fs::write($tmp . '/robots.txt', "User-agent: *\nDisallow: /\n");
            }
        } catch (\Throwable $e) {
            Fs::removeDir($tmp);
            throw $e;
        }

        // 3. atomic swap
        $old = null;
        if (is_dir($out)) {
            $old = dirname($out) . '/.lou-old-' . $id;
            if (!rename($out, $old)) {
                Fs::removeDir($tmp);
                throw new LouException("Cannot move current build out of the way: $out");
            }
        }
        if (!rename($tmp, $out)) {
            if ($old !== null) {
                rename($old, $out);
            }
            throw new LouException("Cannot swap in the new build at $out");
        }

        // 4. bookkeeping
        if ($live) {
            Fs::writeJson($redirectsFile, $entries);
            Fs::writeJson($manifestFile, $manifest);
            if ($old !== null) {
                $this->archive($old, $id);
            }
        } elseif ($old !== null) {
            Fs::removeDir($old);
        }

        return ['id' => $id, 'output' => $out, 'pages' => $count, 'warnings' => $warnings, 'redirects' => $report];
    }

    private function writeHtaccess(string $dir, Site $site, array $rules, bool $live): void
    {
        $file = $dir . '/.htaccess';
        $base = is_file($file) ? (string) file_get_contents($file) : '';
        $block = "# Generated by Lou: do not edit, change site/redirects.json instead\n"
            . (Redirects::htaccess($rules) ?: '# (no redirects)');
        $base = str_contains($base, '# LOU:REDIRECTS') ? str_replace('# LOU:REDIRECTS', $block, $base) : $base . "\n" . $block . "\n";
        if (!$live) {
            $base .= "\nHeader always set X-Robots-Tag \"noindex, nofollow\"\n";
        }
        Fs::write($file, $base);

        // per-language 404 pages for prefixed languages
        foreach ($site->languages() as $lang) {
            $prefix = $site->languagePrefix($lang);
            if ($prefix !== '' && $site->page(Page::NOT_FOUND, $lang) !== null) {
                Fs::write("$dir$prefix/.htaccess", "ErrorDocument 404 $prefix/404.html\n");
            }
        }
    }

    private function writeSitemap(string $dir, Site $site, array &$warnings): void
    {
        $base = rtrim((string) $this->app->config['base_url'], '/');
        $robots = "User-agent: *\nAllow: /\n";
        if ($base === '') {
            $warnings[] = 'base_url is not set in site/config.php: sitemap.xml and hreflang links were skipped';
            Fs::write($dir . '/robots.txt', $robots);
            return;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($site->allPages() as $page) {
            if ($page->isSpecial() || ($page->data['sitemap'] ?? true) === false) {
                continue;
            }
            $xml .= "  <url>\n    <loc>" . Html::escape($base . $site->url($page->id, $page->lang)) . "</loc>\n";
            $translations = $site->translations($page->id);
            if (count($translations) > 1) {
                foreach ($translations as $lang => $t) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="' . $lang . '" href="'
                        . Html::escape($base . $site->url($t->id, $lang)) . "\"/>\n";
                }
            }
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";
        Fs::write($dir . '/sitemap.xml', $xml);
        Fs::write($dir . '/robots.txt', $robots . "Sitemap: $base/sitemap.xml\n");
    }

    /** Keep the previous live build for rollback, pruning to keep_builds. */
    private function archive(string $old, string $id): void
    {
        $builds = $this->app->varDir . '/builds';
        Fs::ensureDir($builds);
        if (!@rename($old, "$builds/before-$id")) {
            Fs::copyDir($old, "$builds/before-$id");
            Fs::removeDir($old);
        }
        $all = glob($builds . '/*', GLOB_ONLYDIR) ?: [];
        sort($all);
        $keep = max(1, (int) $this->app->config['keep_builds']);
        foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $dir) {
            Fs::removeDir($dir);
        }
    }
}
