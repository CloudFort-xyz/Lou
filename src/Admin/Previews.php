<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\App;
use Lou\Build\Builder;
use Lou\Content\Workspace;
use Lou\LouException;
use Lou\Support\Fs;
use Lou\View;

/**
 * Preview builds as static files at secret URLs: /_lou/p/<token>/. They need no PHP to view, never get
 * indexed, and survive publishing. Each shows a source: a person's draft, staging, or site/. Kinds:
 *   - "editor":  a person's inline-editing preview of their draft (editor script injected), rotated daily
 *   - "share":   a read-only link to show the client, expires after N days, revocable
 *   - "draft":   an assistant's preview of its draft (stable link)
 *   - "staging": the review preview of everything on staging (stable link)
 */
final class Previews
{
    private ?array $lastResult = null;

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

    /** A person's inline-editing preview of their own draft (rebuilt with $rebuild). $owner: "user:bence". */
    public function editor(string $owner, bool $rebuild = false): string
    {
        $this->cleanup();
        foreach ($this->all() as $token => $p) {
            if ($p['kind'] === 'editor' && ($p['source'] ?? '') === "draft:$owner") {
                if ($rebuild || !is_dir($this->baseDir() . '/' . $token)) {
                    $this->build($token, $p);
                }
                return $token;
            }
        }
        return $this->create('editor', "draft:$owner", 86400, $owner);
    }

    /**
     * $source: "site", "staging" or "draft:<owner>" (that person's draft, or what they see if they have none).
     */
    public function create(string $kind, string $source, int $ttl, ?string $by = null): string
    {
        $token = bin2hex(random_bytes(16));
        $entry = ['kind' => $kind, 'source' => $source, 'created' => gmdate('c'), 'expires' => time() + $ttl, 'by' => $by];
        $this->build($token, $entry);
        $all = $this->all();
        $all[$token] = $entry;
        Fs::writeJson($this->registry(), $all);
        return $token;
    }

    /**
     * (Re)build the one stable preview of a kind + source (e.g. the staging review preview, an assistant's draft).
     * @return array{token: string, result: array}
     */
    public function stable(string $kind, string $source, ?string $by = null, int $ttl = 7 * 86400): array
    {
        $this->cleanup();
        foreach ($this->all() as $token => $p) {
            if ($p['kind'] === $kind && ($p['source'] ?? '') === $source) {
                return ['token' => $token, 'result' => $this->build($token, $p)];
            }
        }
        $token = $this->create($kind, $source, $ttl, $by);
        return ['token' => $token, 'result' => $this->lastResult];
    }

    /** Rebuild the previews that show $source; null = all of them (after staging or site/ changed). */
    public function rebuild(?string $source = null): void
    {
        foreach ($this->all() as $token => $p) {
            if ($source !== null && ($p['source'] ?? 'site') !== $source) {
                continue;
            }
            try {
                $this->build($token, $p);
            } catch (LouException) {
                // a broken draft must not break everyone else's previews; its owner sees the error when saving
            }
        }
    }

    public function rebuildAll(): void
    {
        $this->rebuild();
    }

    public function revokeSource(string $source): void
    {
        foreach ($this->all() as $token => $p) {
            if (($p['source'] ?? '') === $source) {
                $this->revoke($token);
            }
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

    private function sourceApp(string $source): App
    {
        if ($source === 'staging') {
            return Workspace::staging($this->app)->app();
        }
        if (str_starts_with($source, 'draft:')) {
            return Workspace::draft($this->app, substr($source, 6))->app();
        }
        return $this->app;
    }

    private function build(string $token, array $entry): array
    {
        $editor = $entry['kind'] === 'editor';
        $inject = $editor
            ? '<link rel="stylesheet" href="/_lou/editor.css"><script src="/_lou/editor.js" defer data-page="{page}" data-lang="{lang}"></script>'
            : '';
        $source = $this->sourceApp((string) ($entry['source'] ?? 'site'));
        $result = (new Builder($source))->build($editor ? View::MODE_EDIT : View::MODE_PREVIEW, $this->baseDir() . '/' . $token, self::urlPrefix($token), $inject);
        if (!is_file($this->baseDir() . '/.htaccess')) {
            Fs::write($this->baseDir() . '/.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\n    Header always set X-Robots-Tag \"noindex, nofollow\"\n</IfModule>\n");
        }
        return $this->lastResult = $result;
    }

    private function registry(): string
    {
        return $this->app->varDir . '/previews.json';
    }
}
