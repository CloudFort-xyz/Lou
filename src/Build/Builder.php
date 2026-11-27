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
     * @param string|null $outDir    defaults to the configured output dir
     * @param string      $urlPrefix preview builds served from a sub-path (e.g. /_app/p/<token>)
     * @param string      $inject    HTML inserted before </body> in every page (preview only, e.g. the editor script);
     *                               {page} and {lang} are replaced with the page id and language
     * @return array{id: string, output: string, pages: int, warnings: list<string>, redirects: array}
     */
    public function build(string $mode = View::MODE_LIVE, ?string $outDir = null, string $urlPrefix = '', string $inject = ''): array
    {
        $live = $mode === View::MODE_LIVE;
        if ($live && ($urlPrefix !== '' || $inject !== '')) {
            throw new LouException('URL prefixes and injected HTML are for preview builds only');
        }
        $out = rtrim($outDir ?? $this->app->outputDir(), '/');
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $site = Site::load($this->app, $urlPrefix);
        $warnings = $site->warnings();

        // 1. URL manifest + redirects (validated before anything is written)
        $manifest = [];
        foreach ($site->allPages() as $page) {
            if (!$page->isSpecial()) {
                $manifest["{$page->id}|{$page->lang}"] = $site->path($page->id, $page->lang);
            }
        }
        $endpointPrefix = $this->app->endpoint() . '/';
        foreach ($manifest as $key => $path) {
            if (str_starts_with($path, $endpointPrefix) || $path === $this->app->endpoint()) {
                throw new LouException("Page $key would be served at $path, inside the endpoint folder " . $this->app->endpoint()
                    . '/. Change its slug or the endpoint_dir setting.');
            }
        }
        if ($live) {
            array_push($warnings, ...self::legacyFormNames($this->app->siteDir));
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

        // 2. render into a temp dir next to the output (same filesystem → atomic rename),
        //    or in var/ when the output is a document root Lou can't rename (see Output)
        $output = new Output($this->app);
        $swap = $outDir === null ? $output->mode() : Output::RENAME;
        Fs::ensureDir(dirname($out));
        $tmp = $output->tempDir($out, $id, $swap);
        Fs::ensureDir($tmp);
        try {
            $count = 0;
            foreach ($site->allPages() as $page) {
                $html = (new View($site, $page, $mode))->render();
                if ($inject !== '') {
                    $snippet = strtr($inject, ['{page}' => Html::escape($page->id), '{lang}' => Html::escape($page->lang)]);
                    $html = str_contains($html, '</body>') ? str_replace('</body>', $snippet . "\n</body>", $html) : $html . $snippet;
                }
                $url = $site->path($page->id, $page->lang);
                $file = str_ends_with($url, '.html') ? $tmp . $url : $tmp . $url . 'index.html';
                Fs::write($file, $html);
                $count++;
            }

            if (is_dir($this->app->siteDir . '/assets')) {
                Fs::copyDir($this->app->siteDir . '/assets', $tmp . '/assets');
            }
            if ($live) {
                // stubs/.htaccess goes to the web root, stubs/_lou/ to the endpoint folder (setting endpoint_dir)
                $endpoints = $tmp . $this->app->endpoint();
                Fs::write($tmp . '/.htaccess', str_replace('__ENDPOINT_DIR__', (string) $this->app->config['endpoint_dir'], (string) file_get_contents($this->app->stubsDir . '/.htaccess')));
                Fs::copyDir($this->app->stubsDir . '/_lou', $endpoints);
                foreach (glob($endpoints . '/*.php') ?: [] as $stub) {
                    // endpoint stubs require Lou core from outside the web root
                    Fs::write($stub, str_replace('__LOU_ROOT__', var_export($this->app->root, true), (string) file_get_contents($stub)));
                }
                $this->writeHtaccess($tmp, $site, $rules, $out);
                foreach ($rules as $r) {
                    $target = $tmp . rtrim($r['from'], '/') . '/index.html';
                    if ($r['to'] !== null && !str_ends_with($r['from'], '.html') && !is_file($target)) {
                        Fs::write($target, Redirects::fallbackHtml($r['to']));
                    }
                }
                $this->writeSitemap($tmp, $site, $warnings);
            } else {
                // previews: pages + assets only, never indexed, never executable
                Fs::write($tmp . '/robots.txt', "User-agent: *\nDisallow: /\n");
                Fs::write($tmp . '/.htaccess', "<IfModule mod_headers.c>\n    Header always set X-Robots-Tag \"noindex, nofollow\"\n    Header always set Cache-Control \"no-store\"\n</IfModule>\n<FilesMatch \"\\.(php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
            }
        } catch (\Throwable $e) {
            Fs::removeDir($tmp);
            throw $e;
        }

        // 3. atomic swap (live previews under /<endpoint_dir>/p/ survive a publish)
        $previews = $this->app->endpoint('p');
        if ($live && is_dir($out . $previews)) {
            Fs::ensureDir(dirname($tmp . $previews));
            rename($out . $previews, $tmp . $previews);
        }
        $old = $output->swap($tmp, $out, $id, $swap, $warnings);

        // 4. bookkeeping
        if ($live) {
            Fs::writeJson($redirectsFile, $entries);
            Fs::writeJson($manifestFile, $manifest);
            Fs::writeJson($this->app->varDir . '/live.json', ['build' => $id, 'site_hash' => self::siteHash($this->app), 'lou' => \Lou\App::VERSION, 'at' => gmdate('c')]);
            if ($old !== null) {
                $this->archive($old, $id);
            }
        } elseif ($old !== null) {
            Fs::removeDir($old);
        }

        return ['id' => $id, 'output' => $out, 'pages' => $count, 'warnings' => $warnings, 'redirects' => $report];
    }

    /** Fingerprint of everything that affects the build, to detect unpublished changes. */
    /**
     * Form hooks were renamed so the public site doesn't name Lou: data-lou-status → data-status,
     * .lou-error → .form-error, .lou-status--ok/--error → .form-status--ok/--error, and the honeypot (.lou-hp)
     * is hidden with the hidden attribute. Warns about templates and stylesheets still using the old names.
     * @return list<string>
     */
    public static function legacyFormNames(string $siteDir): array
    {
        $found = [];
        foreach (['templates', 'assets'] as $sub) {
            foreach (is_dir("$siteDir/$sub") ? Fs::files("$siteDir/$sub") : [] as $rel) {
                if (preg_match('/\.(php|css|js|html)$/', $rel)
                    && preg_match('/data-lou-(status|error)|\.lou-(hp|error|status)/', (string) file_get_contents("$siteDir/$sub/$rel"))) {
                    $found[] = "$sub/$rel";
                }
            }
        }
        return $found === [] ? [] : ['Old Lou form names in ' . implode(', ', $found) . ': use data-status instead of data-lou-status, '
            . '.form-error, .form-status--ok and .form-status--error instead of .lou-error and .lou-status--…, and drop the .lou-hp rule.'];
    }

    public static function siteHash(App $app): string
    {
        $h = hash_init('sha256');
        foreach (Fs::files($app->siteDir) as $rel) {
            if ($rel === 'redirects.json') {
                continue; // rewritten by the build itself
            }
            hash_update($h, $rel . "\0" . hash_file('sha256', $app->siteDir . '/' . $rel) . "\0");
        }
        return hash_final($h);
    }

    private function writeHtaccess(string $dir, Site $site, array $rules, string $out): void
    {
        $file = $dir . '/.htaccess';
        $base = is_file($file) ? (string) file_get_contents($file) : '';
        $block = "# Generated by Lou: do not edit, change site/redirects.json instead\n"
            . (Redirects::htaccess($rules) ?: '# (no redirects)');
        $base = str_contains($base, '# LOU:REDIRECTS') ? str_replace('# LOU:REDIRECTS', $block, $base) : $base . "\n" . $block . "\n";
        // keep blocks the hosting panel wrote into the live .htaccess (cPanel's PHP version handler and the like)
        $kept = is_file("$out/.htaccess") ? self::panelBlocks((string) file_get_contents("$out/.htaccess")) : '';
        Fs::write($file, $kept !== '' ? $kept . "\n" . $base : $base);

        // per-language 404 pages for prefixed languages
        foreach ($site->languages() as $lang) {
            $prefix = $site->languagePrefix($lang);
            if ($prefix !== '' && $site->page(Page::NOT_FOUND, $lang) !== null) {
                Fs::write("$dir$prefix/.htaccess", "ErrorDocument 404 $prefix/404.html\n");
            }
        }
    }

    /**
     * "# BEGIN name … # END name" blocks that hosting panels (cPanel MultiPHP, LiteSpeed cache, …) put into the
     * document root's .htaccess. They are carried over to every build, so a rebuild never drops the PHP version.
     */
    public static function panelBlocks(string $htaccess): string
    {
        preg_match_all('/^# BEGIN (.+)$.*?^# END \1[ \t]*$/ms', $htaccess, $m);
        return implode("\n", array_map('trim', $m[0]));
    }

    private function writeSitemap(string $dir, Site $site, array &$warnings): void
    {
        $base = rtrim((string) $this->app->config['base_url'], '/');
        $robots = "User-agent: *\nAllow: /\n";
        if ($base !== '' && !$this->app->baseUrlConfigured()) {
            $warnings[] = "base_url is still the example ($base): set the real https:// address in site/config.php, the sitemap and hreflang links point there";
        }
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
            $xml .= "  <url>\n    <loc>" . Html::escape($base . $site->path($page->id, $page->lang)) . "</loc>\n";
            $translations = $site->translations($page->id);
            if (count($translations) > 1) {
                foreach ($translations as $lang => $t) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="' . $lang . '" href="'
                        . Html::escape($base . $site->path($t->id, $lang)) . "\"/>\n";
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
