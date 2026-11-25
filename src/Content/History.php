<?php

declare(strict_types=1);

namespace Lou\Content;

use Lou\App;
use Lou\Bridge\PathPolicy;
use Lou\LouException;
use Lou\Support\Diff;
use Lou\Support\Fs;

/**
 * Revision history, like WordPress revisions but for every file (content, templates, styles, menu, config).
 * Each publish stores the old and new version of every file it changes in var/history/<id>/{before,after}/.
 *
 * Config: 'history' => ['keep' => 200, 'days' => 0]   keep the last N publishes (0 = no limit) and/or
 *                                                     drop entries older than N days (0 = never);
 *         'history' => false                          disables it.
 */
final class History
{
    public function __construct(private readonly App $app)
    {
    }

    public function enabled(): bool
    {
        return ($this->app->config['history'] ?? []) !== false;
    }

    private function dir(): string
    {
        return $this->app->varDir . '/history';
    }

    /**
     * Record a publish before it is applied. $changes: the staging workspace's changes; $newDir: where the new versions are.
     * @param array{added: list<string>, modified: list<string>, deleted: list<string>} $changes
     */
    public function record(array $changes, string $newDir, string $by, array $submissions): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $files = [];
        foreach (['added', 'modified', 'deleted'] as $kind) {
            foreach ($changes[$kind] as $path) {
                $files[$path] = $kind;
            }
        }
        if (!$files) {
            return null;
        }
        ksort($files);
        // sortable: second + microseconds, so entries made within one second keep their order
        $id = gmdate('Ymd-His') . '-' . sprintf('%06d', (int) (fmod(microtime(true), 1) * 1_000_000));
        while (is_dir($this->dir() . "/$id")) {
            $id = substr($id, 0, 16) . sprintf('%06d', ((int) substr($id, 16) + 1) % 1_000_000);
        }
        $dir = $this->dir() . "/$id";
        foreach ($files as $path => $kind) {
            if ($kind !== 'added' && is_file($this->app->siteDir . "/$path")) {
                Fs::ensureDir(dirname("$dir/before/$path"));
                copy($this->app->siteDir . "/$path", "$dir/before/$path");
            }
            if ($kind !== 'deleted' && is_file("$newDir/$path")) {
                Fs::ensureDir(dirname("$dir/after/$path"));
                copy("$newDir/$path", "$dir/after/$path");
            }
        }
        $subs = array_map(fn ($s) => ['by' => $s['by'], 'summary' => $s['summary'], 'at' => $s['at']], $submissions);
        Fs::writeJson("$dir/entry.json", ['id' => $id, 'at' => gmdate('c'), 'by' => $by, 'submissions' => $subs, 'files' => $files]);
        $this->prune();
        return $id;
    }

    /** @return list<array{id: string, at: string, by: string, submissions: list<array>, files: array<string, string>}> newest first */
    public function all(?string $path = null): array
    {
        $out = [];
        foreach (array_reverse(glob($this->dir() . '/*/entry.json') ?: []) as $f) {
            $e = Fs::readJson($f);
            if ($path === null || isset($e['files'][$path])) {
                $out[] = $e;
            }
        }
        return $out;
    }

    public function get(string $id): array
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id) || !is_file($this->dir() . "/$id/entry.json")) {
            throw new LouException('Unknown history entry');
        }
        return Fs::readJson($this->dir() . "/$id/entry.json");
    }

    /** @return array<string, string> path => unified diff (old → new) */
    public function diffs(string $id): array
    {
        $e = $this->get($id);
        $out = [];
        foreach ($e['files'] as $path => $kind) {
            if (PathPolicy::isBinary($path)) {
                $out[$path] = "(binary file $kind)\n";
                continue;
            }
            $out[$path] = Diff::unified($this->version($id, 'before', $path) ?? '', $this->version($id, 'after', $path) ?? '');
        }
        return $out;
    }

    public function version(string $id, string $side, string $path): ?string
    {
        $file = $this->dir() . "/$id/$side/" . PathPolicy::normalise($path);
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * Put versions from an entry into a draft: 'before' undoes that publish, 'after' brings its result back.
     * @param list<string>|null $paths null = every file of the entry
     * @return list<string> the files written into the draft
     */
    public function restoreInto(Workspace $draft, string $id, string $side, ?array $paths = null): array
    {
        $e = $this->get($id);
        if (!in_array($side, ['before', 'after'], true)) {
            throw new LouException('Unknown version');
        }
        $draft->open();
        $done = [];
        foreach ($e['files'] as $path => $kind) {
            if ($paths !== null && !in_array($path, $paths, true)) {
                continue;
            }
            $src = $this->dir() . "/$id/$side/$path";
            $dst = $draft->siteDir() . "/$path";
            if (is_file($src)) {
                Fs::ensureDir(dirname($dst));
                copy($src, $dst);
            } elseif (is_file($dst)) {
                if (in_array($path, ['config.php', 'menu.json'], true)) {
                    continue;
                }
                unlink($dst);
            }
            $done[] = $path;
        }
        $draft->touch();
        if (!$draft->hasChanges()) {
            $draft->discard();
        }
        return $done;
    }

    private function prune(): void
    {
        $cfg = (array) ($this->app->config['history'] ?? []);
        $keep = (int) ($cfg['keep'] ?? 200);
        $days = (int) ($cfg['days'] ?? 0);
        $dirs = glob($this->dir() . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);
        $cutoff = $days > 0 ? gmdate('Ymd-His', time() - $days * 86400) : null;
        foreach ($dirs as $i => $dir) {
            $tooMany = $keep > 0 && $i < count($dirs) - $keep;
            $tooOld = $cutoff !== null && basename($dir) < $cutoff;
            if ($tooMany || $tooOld) {
                Fs::removeDir($dir);
            }
        }
    }
}
