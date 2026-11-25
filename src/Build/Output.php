<?php

declare(strict_types=1);

namespace Lou\Build;

use Lou\App;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * Puts a finished build in place as the web root.
 *
 * Two ways, set with 'output_swap' in site/config.php (the installer sets it in var/settings.json):
 *
 * - 'rename' (default): the build is rendered next to the output folder and swapped in with two renames.
 *   Atomic, but the output folder must be Lou's alone and its parent writable (lou/public as the docroot).
 * - 'contents': for a document root Lou can't rename, like a fixed public_html. The build is rendered in
 *   var/ and swapped in one top-level entry at a time. Only the entries Lou wrote last time (listed in
 *   var/output.json) are replaced, so the host's own files (cgi-bin, .well-known, other domains' folders)
 *   stay where they are. A foreign file that the build needs to replace (an old index.html, the host's
 *   .htaccess) is moved to var/replaced/<build id>/ once, never deleted.
 */
final class Output
{
    public const RENAME = 'rename';
    public const CONTENTS = 'contents';

    public function __construct(private readonly App $app)
    {
    }

    public function mode(): string
    {
        $mode = (string) ($this->app->config['output_swap'] ?? self::RENAME);
        if (!in_array($mode, [self::RENAME, self::CONTENTS], true)) {
            throw new LouException("output_swap must be 'rename' or 'contents', not '$mode'");
        }
        return $mode;
    }

    /** Where to render a build before it is swapped in to $out. */
    public function tempDir(string $out, string $id, string $mode): string
    {
        return $mode === self::CONTENTS ? $this->app->varDir . "/.lou-tmp-$id" : dirname($out) . "/.lou-tmp-$id";
    }

    /**
     * Swap $tmp in as $out. Returns the folder that now holds the previous build (to archive or delete),
     * or null when there was none. On failure the previous build stays live (rename) or is put back (contents).
     * @param list<string> $warnings receives notes about foreign files that were moved aside
     */
    public function swap(string $tmp, string $out, string $id, string $mode, array &$warnings = []): ?string
    {
        return $mode === self::CONTENTS ? $this->swapContents($tmp, $out, $id, $warnings) : $this->swapRename($tmp, $out, $id);
    }

    private function swapRename(string $tmp, string $out, string $id): ?string
    {
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
        return $old;
    }

    /** @param list<string> $warnings */
    private function swapContents(string $tmp, string $out, string $id, array &$warnings): ?string
    {
        if (is_link($out) || (!is_dir($out) && file_exists($out))) {
            throw new LouException("$out must be a folder");
        }
        Fs::ensureDir($out);
        $ownedFile = $this->app->varDir . '/output.json';
        $owned = is_file($ownedFile) ? array_values(array_filter(Fs::readJson($ownedFile), 'is_string')) : [];
        $new = self::entries($tmp);
        $old = $this->app->varDir . "/.lou-old-$id";
        $replaced = $this->app->varDir . "/replaced/$id";
        $done = [];   // [from, to] moves to undo if something fails

        try {
            foreach (self::entries($out) as $name) {
                if (in_array($name, $owned, true)) {
                    Fs::ensureDir($old);
                    self::move("$out/$name", "$old/$name");
                    $done[] = ["$old/$name", "$out/$name"];
                } elseif (in_array($name, $new, true)) {
                    Fs::ensureDir($replaced);
                    self::move("$out/$name", "$replaced/$name");
                    $done[] = ["$replaced/$name", "$out/$name"];
                    $warnings[] = "$out/$name was not written by Lou: it was moved to var/replaced/$id/ to make room for the site";
                }
            }
            $placed = [];
            foreach ($new as $name) {
                self::move("$tmp/$name", "$out/$name");
                $placed[] = "$out/$name";
            }
        } catch (\Throwable $e) {
            foreach ($placed ?? [] as $p) {
                is_dir($p) && !is_link($p) ? Fs::removeDir($p) : @unlink($p);
            }
            foreach (array_reverse($done) as [$from, $to]) {
                @rename($from, $to);
            }
            Fs::removeDir($tmp);
            Fs::removeDir($old);
            throw new LouException("Cannot swap in the new build at $out: " . $e->getMessage());
        }
        Fs::removeDir($tmp);
        Fs::writeJson($ownedFile, $new);
        return is_dir($old) ? $old : null;
    }

    /** @return list<string> top-level names in $dir */
    private static function entries(string $dir): array
    {
        $out = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** rename(), or copy + delete when $from and $to are on different file systems. */
    private static function move(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }
        if (is_dir($from) && !is_link($from)) {
            Fs::copyDir($from, $to);
            Fs::removeDir($from);
        } elseif (copy($from, $to)) {
            unlink($from);
        } else {
            throw new LouException("Cannot move $from to $to");
        }
    }
}
