<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\App;
use Lou\Build\Builder;
use Lou\Support\Fs;
use Lou\View;

/**
 * Preview builds of the current (unpublished) site/ as static files at secret URLs: /_lou/p/<token>/.
 * They need no PHP to view, never get indexed, and survive publishing. Kinds:
 *   - "editor": the admin's inline-editing preview (with the editor script injected), rotated daily
 *   - "share":  a read-only link to show the client, expires after N days, revocable
 */
final class Previews
{
    public function __construct(private readonly App $app)
    {
    }

    public function baseDir(): string
    {
        return $this->app->outputDir() . '/_lou/p';
    }

    public static function urlPrefix(string $token): string
    {
        return '/_lou/p/' . $token;
    }

    /** @return array<string, array{kind: string, created: string, expires: int, by: ?string}> */
    public function all(): array
    {
        $f = $this->registry();
        return is_file($f) ? Fs::readJson($f) : [];
    }

    /** The editor preview token (rebuilt with $rebuild). */
    public function editor(bool $rebuild = false): string
    {
        $this->cleanup();
        foreach ($this->all() as $token => $p) {
            if ($p['kind'] === 'editor') {
                if ($rebuild || !is_dir($this->baseDir() . '/' . $token)) {
                    $this->build($token, true);
                }
                return $token;
            }
        }
        return $this->create('editor', 86400);
    }

    public function create(string $kind, int $ttl, ?string $by = null): string
    {
        $token = bin2hex(random_bytes(16));
        $this->build($token, $kind === 'editor');
        $all = $this->all();
        $all[$token] = ['kind' => $kind, 'created' => gmdate('c'), 'expires' => time() + $ttl, 'by' => $by];
        Fs::writeJson($this->registry(), $all);
        return $token;
    }

    /** Rebuild every live preview (after content changes). */
    public function rebuildAll(): void
    {
        foreach ($this->all() as $token => $p) {
            $this->build($token, $p['kind'] === 'editor');
        }
    }

    public function revoke(string $token): void
    {
        $all = $this->all();
        unset($all[$token]);
        Fs::writeJson($this->registry(), $all);
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            Fs::removeDir($this->baseDir() . '/' . $token);
        }
    }

    public function cleanup(): void
    {
        foreach ($this->all() as $token => $p) {
            if ($p['expires'] < time()) {
                $this->revoke($token);
            }
        }
        // remove orphaned dirs (e.g. from a crashed build)
        foreach (glob($this->baseDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!isset($this->all()[basename($dir)])) {
                Fs::removeDir($dir);
            }
        }
    }

    private function build(string $token, bool $editor): void
    {
        $inject = $editor
            ? '<link rel="stylesheet" href="/_lou/editor.css"><script src="/_lou/editor.js" defer data-page="{page}" data-lang="{lang}"></script>'
            : '';
        (new Builder($this->app))->build($editor ? View::MODE_EDIT : View::MODE_PREVIEW, $this->baseDir() . '/' . $token, self::urlPrefix($token), $inject);
        if (!is_file($this->baseDir() . '/.htaccess')) {
            Fs::write($this->baseDir() . '/.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\n    Header always set X-Robots-Tag \"noindex, nofollow\"\n</IfModule>\n");
        }
    }

    private function registry(): string
    {
        return $this->app->varDir . '/previews.json';
    }
}
