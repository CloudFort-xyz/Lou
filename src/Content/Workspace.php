<?php

declare(strict_types=1);

namespace Lou\Content;

use Lou\App;
use Lou\Bridge\PathPolicy;
use Lou\LouException;
use Lou\Support\Diff;
use Lou\Support\Fs;

/**
 * A working copy of site/ with a snapshot ("base") of what it was copied from ("upstream").
 *
 *   draft (one per admin user / assistant)  →  submit  →  staging (shared)  →  publish  →  site/  →  build
 *
 * A draft's upstream is staging when staging is open, otherwise site/. Staging's upstream is site/.
 * Applying copies only the files changed in the workspace (base → copy), so other people's work
 * upstream is kept; a file changed on both sides is a conflict. redirects.json is merged by 'from'.
 */
final class Workspace
{
    private function __construct(
        private readonly App $app,
        public readonly string $dir,
        private readonly string $kind,  // 'draft' | 'staging'
        public readonly string $owner,
    ) {
    }

    public static function staging(App $app): self
    {
        return new self($app, $app->varDir . '/staging', 'staging', '');
    }

    /** $owner: "user:bence" or "llm:Claude – Bence" */
    public static function draft(App $app, string $owner): self
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($owner)), '-');
        return new self($app, $app->varDir . '/drafts/' . substr($slug, 0, 40) . '-' . substr(hash('sha256', $owner), 0, 8), 'draft', $owner);
    }

    /** @return list<self> */
    public static function allDrafts(App $app): array
    {
        $out = [];
        foreach (glob($app->varDir . '/drafts/*/workspace.json') ?: [] as $f) {
            $owner = (string) (Fs::readJson($f)['owner'] ?? '');
            if ($owner !== '') {
                $out[] = self::draft($app, $owner);
            }
        }
        return $out;
    }

    public function siteDir(): string
    {
        return $this->dir . '/site';
    }

    private function baseDir(): string
    {
        return $this->dir . '/base';
    }

    /** The directory this workspace was copied from and applies to. */
    public function upstreamDir(): string
    {
        if ($this->kind === 'draft') {
            $staging = self::staging($this->app);
            return $staging->exists() ? $staging->siteDir() : $this->app->siteDir;
        }
        return $this->app->siteDir;
    }

    public function exists(): bool
    {
        return is_file($this->dir . '/workspace.json');
    }

    public function meta(): array
    {
        if (!$this->exists()) {
            throw new LouException($this->kind === 'staging' ? 'Nothing is on staging' : 'There is no draft');
        }
        return Fs::readJson($this->dir . '/workspace.json');
    }

    public function open(): array
    {
        if ($this->exists()) {
            return $this->meta();
        }
        Fs::removeDir($this->dir);
        Fs::copyDir($this->upstreamDir(), $this->baseDir());
        Fs::copyDir($this->upstreamDir(), $this->siteDir());
        $meta = ['owner' => $this->owner, 'created' => gmdate('c'), 'updated' => gmdate('c'), 'rev' => 0];
        if ($this->kind === 'staging') {
            $meta['submissions'] = [];
        }
        $this->saveMeta($meta);
        return $meta;
    }

    /** An App whose site/ is this workspace, or the upstream if it isn't open (what this person sees). */
    public function app(): App
    {
        return new App($this->app->root, $this->exists() ? $this->siteDir() : $this->upstreamDir());
    }

    public function discard(): void
    {
        Fs::removeDir($this->dir);
    }

    // ---------------------------------------------------------------- file operations

    /** @return list<string> */
    public function listFiles(string $prefix = ''): array
    {
        $files = Fs::files($this->app()->siteDir);
        $prefix = trim($prefix, '/');
        return array_values(array_filter($files, fn ($f) => $prefix === '' || str_starts_with($f, $prefix . '/') || $f === $prefix));
    }

    public function read(string $path): string
    {
        $path = PathPolicy::normalise($path);
        $file = $this->app()->siteDir . '/' . $path;
        if (!is_file($file)) {
            throw new LouException("No such file: $path");
        }
        if (PathPolicy::isBinary($path)) {
            $info = @getimagesize($file);
            return '(binary file, ' . filesize($file) . ' bytes' . ($info ? ", {$info[0]}×{$info[1]}" : '') . ')';
        }
        return (string) file_get_contents($file);
    }

    /** Write a file through the bridge rules (path policy + content checks). */
    public function write(string $path, string $content): void
    {
        $path = PathPolicy::normalise($path);
        PathPolicy::checkWrite($path);
        $problems = PathPolicy::checkContent($path, $content);
        if ($problems) {
            throw new LouException('Rejected:', $problems);
        }
        $this->open();
        Fs::write($this->siteDir() . '/' . $path, $content);
        $this->touch();
    }

    public function delete(string $path): void
    {
        $path = PathPolicy::normalise($path);
        if (in_array($path, ['config.php', 'menu.json'], true)) {
            throw new LouException("$path can be changed but not deleted");
        }
        $this->open();
        $file = $this->siteDir() . '/' . $path;
        if (!is_file($file)) {
            throw new LouException("No such file: $path");
        }
        unlink($file);
        self::pruneDirs(dirname($file), $this->siteDir());
        $this->touch();
    }

    /** Record that the working copy changed (call after writing into siteDir() directly, e.g. via ContentStore). */
    public function touch(): void
    {
        $meta = $this->meta();
        $meta['updated'] = gmdate('c');
        $meta['rev'] = (int) ($meta['rev'] ?? 0) + 1;
        $this->saveMeta($meta);
    }

    // ---------------------------------------------------------------- changes

    /** @return array{added: list<string>, modified: list<string>, deleted: list<string>} */
    public function changes(): array
    {
        if (!$this->exists()) {
            return ['added' => [], 'modified' => [], 'deleted' => []];
        }
        return self::compare($this->baseDir(), $this->siteDir());
    }

    /** @return list<string> */
    public function changedFiles(): array
    {
        $c = $this->changes();
        $all = array_merge($c['added'], $c['modified'], $c['deleted']);
        sort($all);
        return $all;
    }

    public function hasChanges(): bool
    {
        return $this->changedFiles() !== [];
    }

    /** Files changed upstream since the snapshot that this workspace changed too. */
    public function conflicts(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $up = self::compare($this->baseDir(), $this->upstreamDir());
        $upAll = array_merge($up['added'], $up['modified'], $up['deleted']);
        return array_values(array_diff(array_intersect($this->changedFiles(), $upAll), ['redirects.json']));
    }

    /** @return array{0: ?string, 1: ?string} a changed file's contents before and after (null: no such file on that side) */
    public function versions(string $path): array
    {
        $read = static fn (string $f): ?string => is_file($f) ? (string) file_get_contents($f) : null;
        return [$read($this->baseDir() . "/$path"), $read($this->siteDir() . "/$path")];
    }

    /** @return array<string, string> path => unified diff */
    public function diffs(): array
    {
        $out = [];
        $c = $this->changes();
        foreach ($this->changedFiles() as $path) {
            if (PathPolicy::isBinary($path)) {
                $out[$path] = in_array($path, $c['deleted'], true) ? "(binary file deleted)\n" : "(binary file)\n";
                continue;
            }
            $old = is_file($this->baseDir() . "/$path") ? (string) file_get_contents($this->baseDir() . "/$path") : '';
            $new = is_file($this->siteDir() . "/$path") ? (string) file_get_contents($this->siteDir() . "/$path") : '';
            $out[$path] = Diff::unified($old, $new);
        }
        return $out;
    }

    /** @return list<string> problems that block submitting / publishing */
    public function validate(): array
    {
        $problems = [];
        $c = $this->changes();
        foreach (array_merge($c['added'], $c['modified']) as $path) {
            if (!PathPolicy::isBinary($path) && (str_starts_with($path, 'templates/') || $path === 'config.php' || !str_ends_with($path, '.php'))) {
                array_push($problems, ...PathPolicy::checkContent($path, (string) file_get_contents($this->siteDir() . "/$path")));
            }
        }
        try {
            Site::load($this->app());
        } catch (LouException $e) {
            $problems[] = $e->getMessage();
        }
        return $problems;
    }

    /**
     * Bring in upstream changes for every file this workspace did not change (so a draft sees what
     * others submitted or published). Files changed on both sides are left alone: they are conflicts.
     */
    public function rebase(): void
    {
        if (!$this->exists()) {
            return;
        }
        $mine = array_flip($this->changedFiles());
        $up = self::compare($this->baseDir(), $this->upstreamDir());
        $synced = false;
        foreach (array_merge($up['added'], $up['modified'], $up['deleted']) as $path) {
            if (!isset($mine[$path])) {
                $this->sync($path);
                $synced = true;
            }
        }
        if ($synced && !$this->hasChanges() && $this->kind === 'draft') {
            $this->discard(); // nothing of our own left: fall back to viewing upstream
        }
    }

    /** Throw away this workspace's version of some files and take the upstream version. */
    public function revert(array $paths): void
    {
        foreach ($paths as $path) {
            $this->sync(PathPolicy::normalise((string) $path));
        }
        if ($this->exists()) {
            $this->touch();
            if ($this->kind === 'draft' && !$this->hasChanges()) {
                $this->discard();
            }
        }
    }

    private function sync(string $path): void
    {
        $src = $this->upstreamDir() . "/$path";
        foreach ([$this->baseDir(), $this->siteDir()] as $root) {
            if (is_file($src)) {
                Fs::ensureDir(dirname("$root/$path"));
                copy($src, "$root/$path");
            } elseif (is_file("$root/$path")) {
                unlink("$root/$path");
                self::pruneDirs(dirname("$root/$path"), $root);
            }
        }
    }

    /**
     * Copy this workspace's changed files into its upstream (opening staging first for a draft),
     * then discard it. Throws on validation problems, or on conflicts unless $force.
     * @return list<string> the applied files
     */
    public function apply(bool $force = false): array
    {
        $problems = $this->validate();
        if ($problems) {
            throw new LouException('The changes cannot be applied:', $problems);
        }
        $conflicts = $this->conflicts();
        if ($conflicts && !$force) {
            throw new LouException($this->kind === 'draft'
                ? 'Someone else changed these files on staging since you started:'
                : 'These files were changed on the live site since staging was opened:', $conflicts);
        }
        if ($this->kind === 'draft') {
            self::staging($this->app)->open();
        }
        $target = $this->upstreamDir();
        $c = $this->changes();
        foreach (array_merge($c['added'], $c['modified']) as $path) {
            if ($path === 'redirects.json') {
                $this->mergeRedirects($target);
                continue;
            }
            Fs::ensureDir(dirname("$target/$path"));
            $tmp = "$target/$path.lou-tmp";
            copy($this->siteDir() . "/$path", $tmp);
            rename($tmp, "$target/$path");
        }
        foreach ($c['deleted'] as $path) {
            @unlink("$target/$path");
            self::pruneDirs(dirname("$target/$path"), $target);
        }
        $files = $this->changedFiles();
        $this->discard();
        return $files;
    }

    /** Staging only: record who submitted what. */
    public function addSubmission(string $by, string $summary, array $files): void
    {
        $meta = $this->meta();
        $meta['submissions'][] = ['by' => $by, 'summary' => mb_substr(trim($summary), 0, 2000), 'at' => gmdate('c'), 'files' => $files];
        $this->saveMeta($meta);
        $this->touch();
    }

    /** Remember the last preview build (link, warnings, redirect report) for the review screen. */
    public function recordPreview(string $url, array $result): void
    {
        Fs::writeJson($this->dir . '/preview.json', ['url' => $url, 'at' => gmdate('c'), 'rev' => $this->meta()['rev'] ?? 0,
            'pages' => $result['pages'], 'warnings' => $result['warnings'], 'redirects' => $result['redirects']]);
    }

    public function lastPreview(): ?array
    {
        $file = $this->dir . '/preview.json';
        return is_file($file) ? Fs::readJson($file) : null;
    }

    // ---------------------------------------------------------------- helpers

    /** Live builds add automatic redirects meanwhile; merge by 'from' with this workspace's entries winning. */
    private function mergeRedirects(string $target): void
    {
        $live = is_file("$target/redirects.json") ? Fs::readJson("$target/redirects.json") : [];
        $base = is_file($this->baseDir() . '/redirects.json') ? Fs::readJson($this->baseDir() . '/redirects.json') : [];
        $mine = Fs::readJson($this->siteDir() . '/redirects.json');
        $byFrom = [];
        foreach ($live as $e) {
            $byFrom[$e['from']] = $e;
        }
        $mineFrom = array_column($mine, 'from');
        foreach ($base as $e) {
            if (!in_array($e['from'], $mineFrom, true)) {
                unset($byFrom[$e['from']]); // deleted here
            }
        }
        foreach ($mine as $e) {
            $byFrom[$e['from']] = $e;
        }
        ksort($byFrom);
        Fs::writeJson("$target/redirects.json", array_values($byFrom));
    }

    private function saveMeta(array $meta): void
    {
        Fs::writeJson($this->dir . '/workspace.json', $meta);
    }

    private static function pruneDirs(string $dir, string $root): void
    {
        while ($dir !== $root && str_starts_with($dir, $root) && is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
            $dir = dirname($dir);
        }
    }

    /** @return array{added: list<string>, modified: list<string>, deleted: list<string>} */
    private static function compare(string $from, string $to): array
    {
        $a = array_flip(is_dir($from) ? Fs::files($from) : []);
        $b = array_flip(is_dir($to) ? Fs::files($to) : []);
        $out = ['added' => [], 'modified' => [], 'deleted' => []];
        foreach ($b as $f => $_) {
            if (!isset($a[$f])) {
                $out['added'][] = $f;
            } elseif (filesize("$from/$f") !== filesize("$to/$f") || hash_file('sha256', "$from/$f") !== hash_file('sha256', "$to/$f")) {
                $out['modified'][] = $f;
            }
        }
        foreach ($a as $f => $_) {
            if (!isset($b[$f])) {
                $out['deleted'][] = $f;
            }
        }
        return $out;
    }
}
