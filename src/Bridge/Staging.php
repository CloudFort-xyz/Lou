<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\App;
use Lou\Content\Site;
use Lou\LouException;
use Lou\Support\Diff;
use Lou\Support\Fs;

/**
 * The LLM's working copy of site/ (var/staging/site), plus a snapshot of site/ taken when the
 * changeset was opened (var/staging/base). Only the files the LLM changed are applied on approval,
 * so content edits made in the admin meanwhile are kept; a file changed on both sides is a conflict.
 */
final class Staging
{
    public function __construct(private readonly App $app)
    {
    }

    public function dir(): string
    {
        return $this->app->varDir . '/staging';
    }

    public function siteDir(): string
    {
        return $this->dir() . '/site';
    }

    private function baseDir(): string
    {
        return $this->dir() . '/base';
    }

    public function exists(): bool
    {
        return is_file($this->dir() . '/changeset.json');
    }

    /** @return array{id: string, status: string, created: string, by: string, summary: string, updated: string} */
    public function meta(): array
    {
        if (!$this->exists()) {
            throw new LouException('There is no open changeset');
        }
        return Fs::readJson($this->dir() . '/changeset.json');
    }

    public function open(string $by): array
    {
        if ($this->exists()) {
            return $this->meta();
        }
        Fs::removeDir($this->dir());
        Fs::copyDir($this->app->siteDir, $this->baseDir());
        Fs::copyDir($this->app->siteDir, $this->siteDir());
        $meta = ['id' => gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)), 'status' => 'open', 'created' => gmdate('c'),
            'by' => $by, 'summary' => '', 'updated' => gmdate('c')];
        Fs::writeJson($this->dir() . '/changeset.json', $meta);
        return $meta;
    }

    /** An App whose site/ is the staging copy (for building previews and validating). */
    public function app(): App
    {
        return new App($this->app->root, $this->siteDir());
    }

    // ---------------------------------------------------------------- file operations

    /** @return list<string> */
    public function listFiles(string $prefix = ''): array
    {
        $files = Fs::files($this->exists() ? $this->siteDir() : $this->app->siteDir);
        $prefix = trim($prefix, '/');
        return array_values(array_filter($files, fn ($f) => $prefix === '' || str_starts_with($f, $prefix . '/') || $f === $prefix));
    }

    public function read(string $path): string
    {
        $path = PathPolicy::normalise($path);
        $file = ($this->exists() ? $this->siteDir() : $this->app->siteDir) . '/' . $path;
        if (!is_file($file)) {
            throw new LouException("No such file: $path");
        }
        if (PathPolicy::isBinary($path)) {
            $info = @getimagesize($file);
            return "(binary file, " . filesize($file) . " bytes" . ($info ? ", {$info[0]}×{$info[1]}" : '') . ")";
        }
        return (string) file_get_contents($file);
    }

    public function write(string $path, string $content, string $by): void
    {
        $path = PathPolicy::normalise($path);
        PathPolicy::checkWrite($path);
        $problems = PathPolicy::checkContent($path, $content);
        if ($problems) {
            throw new LouException('Rejected:', $problems);
        }
        $this->open($by);
        Fs::write($this->siteDir() . '/' . $path, $content);
        $this->touch();
    }

    public function delete(string $path, string $by): void
    {
        $path = PathPolicy::normalise($path);
        if (in_array($path, ['config.php', 'menu.json'], true)) {
            throw new LouException("$path can be changed but not deleted");
        }
        $this->open($by);
        $file = $this->siteDir() . '/' . $path;
        if (!is_file($file)) {
            throw new LouException("No such file: $path");
        }
        unlink($file);
        // remove now-empty page folders
        $dir = dirname($file);
        while ($dir !== $this->siteDir() && is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
            $dir = dirname($dir);
        }
        $this->touch();
    }

    private function touch(): void
    {
        $meta = $this->meta();
        $meta['updated'] = gmdate('c');
        if ($meta['status'] === 'review') {
            $meta['status'] = 'open'; // changed after submitting: needs re-submitting
        }
        Fs::writeJson($this->dir() . '/changeset.json', $meta);
    }

    // ---------------------------------------------------------------- review

    /** @return array{added: list<string>, modified: list<string>, deleted: list<string>} */
    public function changes(): array
    {
        if (!$this->exists()) {
            return ['added' => [], 'modified' => [], 'deleted' => []];
        }
        return self::compare($this->baseDir(), $this->siteDir());
    }

    /** Files changed in the live site/ since the changeset was opened that the changeset also touches. */
    public function conflicts(): array
    {
        $live = self::compare($this->baseDir(), $this->app->siteDir);
        $mine = $this->changes();
        $liveAll = array_merge($live['added'], $live['modified'], $live['deleted']);
        $mineAll = array_merge($mine['added'], $mine['modified'], $mine['deleted']);
        return array_values(array_diff(array_intersect($mineAll, $liveAll), ['redirects.json']));
    }

    /** @return array<string, string> path => unified diff */
    public function diffs(): array
    {
        $out = [];
        $c = $this->changes();
        foreach (array_merge($c['added'], $c['modified'], $c['deleted']) as $path) {
            if (PathPolicy::isBinary($path)) {
                $out[$path] = in_array($path, $c['deleted'], true) ? "(binary file deleted)\n" : "(binary file)\n";
                continue;
            }
            $old = is_file($this->baseDir() . "/$path") ? (string) file_get_contents($this->baseDir() . "/$path") : '';
            $new = is_file($this->siteDir() . "/$path") ? (string) file_get_contents($this->siteDir() . "/$path") : '';
            $out[$path] = Diff::unified($old, $new);
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> problems that block approval */
    public function validate(): array
    {
        $problems = [];
        $c = $this->changes();
        foreach (array_merge($c['added'], $c['modified']) as $path) {
            if (!PathPolicy::isBinary($path)) {
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

    /** Remember the last preview build (link, warnings, redirect report) for the review screen. */
    public function recordPreview(string $url, array $result): void
    {
        Fs::writeJson($this->dir() . '/preview.json', ['url' => $url, 'at' => gmdate('c'), 'pages' => $result['pages'],
            'warnings' => $result['warnings'], 'redirects' => $result['redirects']]);
    }

    /** @return array{url: string, at: string, pages: int, warnings: list<string>, redirects: array}|null */
    public function lastPreview(): ?array
    {
        $file = $this->dir() . '/preview.json';
        return is_file($file) ? Fs::readJson($file) : null;
    }

    public function submit(string $summary): array
    {
        $meta = $this->meta();
        $meta['status'] = 'review';
        $meta['summary'] = mb_substr(trim($summary), 0, 2000);
        $meta['updated'] = gmdate('c');
        Fs::writeJson($this->dir() . '/changeset.json', $meta);
        return $meta;
    }

    /**
     * Apply the changeset's files to site/. Throws on validation problems, or on conflicts unless $force.
     * @return array{added: list<string>, modified: list<string>, deleted: list<string>}
     */
    public function apply(bool $force = false): array
    {
        $problems = $this->validate();
        if ($problems) {
            throw new LouException('The changes cannot be applied:', $problems);
        }
        $conflicts = $this->conflicts();
        if ($conflicts && !$force) {
            throw new LouException('These files were also changed in the admin since the LLM started:', $conflicts);
        }
        $c = $this->changes();
        foreach (array_merge($c['added'], $c['modified']) as $path) {
            if ($path === 'redirects.json') {
                $this->mergeRedirects();
                continue;
            }
            Fs::ensureDir(dirname($this->app->siteDir . "/$path"));
            $tmp = $this->app->siteDir . "/$path.lou-tmp";
            copy($this->siteDir() . "/$path", $tmp);
            rename($tmp, $this->app->siteDir . "/$path");
        }
        foreach ($c['deleted'] as $path) {
            @unlink($this->app->siteDir . "/$path");
            $dir = dirname($this->app->siteDir . "/$path");
            while ($dir !== $this->app->siteDir && is_dir($dir) && count(scandir($dir)) === 2) {
                rmdir($dir);
                $dir = dirname($dir);
            }
        }
        $this->discard();
        return $c;
    }

    /** Live builds add automatic redirects meanwhile; merge by 'from' with the LLM's entries winning. */
    private function mergeRedirects(): void
    {
        $live = is_file($this->app->siteDir . '/redirects.json') ? Fs::readJson($this->app->siteDir . '/redirects.json') : [];
        $base = is_file($this->baseDir() . '/redirects.json') ? Fs::readJson($this->baseDir() . '/redirects.json') : [];
        $mine = Fs::readJson($this->siteDir() . '/redirects.json');
        $byFrom = [];
        foreach ($live as $e) {
            $byFrom[$e['from']] = $e;
        }
        $mineFrom = array_column($mine, 'from');
        foreach ($base as $e) {
            if (!in_array($e['from'], $mineFrom, true)) {
                unset($byFrom[$e['from']]); // deleted by the LLM
            }
        }
        foreach ($mine as $e) {
            $byFrom[$e['from']] = $e;
        }
        ksort($byFrom);
        Fs::writeJson($this->app->siteDir . '/redirects.json', array_values($byFrom));
    }

    public function discard(): void
    {
        Fs::removeDir($this->dir());
    }

    /** @return array{added: list<string>, modified: list<string>, deleted: list<string>} */
    private static function compare(string $from, string $to): array
    {
        $a = array_flip(Fs::files($from));
        $b = array_flip(Fs::files($to));
        $out = ['added' => [], 'modified' => [], 'deleted' => []];
        foreach ($b as $f => $_) {
            if (!isset($a[$f])) {
                $out['added'][] = $f;
            } elseif (hash_file('sha256', "$from/$f") !== hash_file('sha256', "$to/$f")) {
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
