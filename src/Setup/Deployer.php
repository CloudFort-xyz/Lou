<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\LouException;
use Lou\Support\Fs;

/**
 * Copies Lou's code from a checkout (e.g. the cPanel Git clone) into a live install.
 *
 * Code is replaced; content and runtime data are not. site/ is seeded from the checkout only when the
 * install has none, because after that the server's site/ is the truth (editor, assistants, history).
 * var/, .env.php and the build output are never touched.
 *
 * A client site kept in its own repository (with Lou as a submodule) passes its site/ folder as $siteSource,
 * so the first deploy seeds the install from the client's site instead of Lou's example site. In that mode
 * later deploys also bring site/templates/ and site/assets/ up to date from it, except files changed on the
 * server since the last deploy (reported, never overwritten) and site/assets/uploads/ (editor images).
 * Content stays the server's. var/deploy-design.json remembers what the last deploy wrote.
 */
final class Deployer
{
    /** Code shipped on every deploy (folders are replaced whole, files overwritten). */
    public const CODE = ['src', 'stubs', 'vendor-bundled', 'lou', '.env.example.php', 'starters'];

    /** site/ folders a client repository (--site) keeps up to date on every deploy. */
    public const DESIGN = ['templates', 'assets'];

    /** Kept from the server inside the DESIGN folders (editor uploads). */
    public const KEEP = ['assets/uploads'];

    public function __construct(private readonly string $source, private readonly ?string $siteSource = null)
    {
    }

    /**
     * Install folders to deploy to: one absolute path per line in ~/.lou-target (blank lines and # comments
     * ignored, ~/ expanded), or ~/lou when the file is missing or empty.
     * @return list<string>
     */
    public static function targets(string $home): array
    {
        $home = rtrim($home, '/');
        $lines = is_file("$home/.lou-target") ? (file("$home/.lou-target", FILE_IGNORE_NEW_LINES) ?: []) : [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $out[] = str_starts_with($line, '~/') ? $home . substr($line, 1) : $line;
        }
        return $out ?: ["$home/lou"];
    }

    /** @return array{target: string, code: list<string>, seeded: bool, design: array{updated: list<string>, deleted: list<string>, skipped: list<string>}} */
    public function deploy(string $target): array
    {
        $source = rtrim((string) realpath($this->source), '/');
        if ($source === '' || !is_file("$source/lou") || !is_dir("$source/src")) {
            throw new LouException("$this->source is not a Lou checkout");
        }
        $target = rtrim($target, '/');
        if ($target === '' || !str_starts_with($target, '/')) {
            throw new LouException('The install folder must be an absolute path');
        }
        $site = $this->siteSource === null ? "$source/site" : rtrim((string) realpath($this->siteSource), '/');
        if ($this->siteSource !== null && ($site === '' || !is_file("$site/config.php"))) {
            throw new LouException("$this->siteSource is not a Lou site folder (no config.php)");
        }
        Fs::ensureDir($target);
        if (realpath($target) === $source) {
            throw new LouException('The install folder must not be the checkout itself');
        }

        // Stage everything first, then swap each item in, so a failed copy leaves the install as it was.
        $stage = "$target/.lou-deploy-" . bin2hex(random_bytes(4));
        $copied = [];
        try {
            foreach (self::CODE as $item) {
                if (is_dir("$source/$item")) {
                    Fs::copyDir("$source/$item", "$stage/$item");
                } elseif (is_file("$source/$item")) {
                    Fs::ensureDir($stage);
                    if (!copy("$source/$item", "$stage/$item")) {
                        throw new LouException("Cannot copy $source/$item");
                    }
                } else {
                    continue;
                }
                $copied[] = $item;
            }
            foreach ($copied as $item) {
                $this->swap("$stage/$item", "$target/$item");
            }
        } finally {
            Fs::removeDir($stage);
        }
        @chmod("$target/lou", 0755);

        $seeded = false;
        $design = ['updated' => [], 'deleted' => [], 'skipped' => []];
        $manifest = "$target/var/deploy-design.json";
        if (!is_dir("$target/site") && is_dir($site)) {
            Fs::copyDir($site, "$target/site");
            $seeded = true;
            if ($this->siteSource !== null) {
                Fs::writeJson($manifest, self::designHashes($site));
            }
        } elseif ($this->siteSource !== null) {
            $design = $this->syncDesign($site, "$target/site", $manifest);
        }
        return ['target' => $target, 'code' => $copied, 'seeded' => $seeded, 'design' => $design];
    }

    /**
     * Brings site/templates and site/assets up to date with the repository, file by file. A file is written or
     * deleted only when the server copy is still what the last deploy put there; a file changed on the server
     * since (by an assistant, say) is left alone and reported. Editor uploads are never touched.
     * @return array{updated: list<string>, deleted: list<string>, skipped: list<string>}
     */
    private function syncDesign(string $from, string $site, string $manifestFile): array
    {
        $last = is_file($manifestFile) ? Fs::readJson($manifestFile) : [];
        $plan = self::planDesign($from, $site, $last);
        foreach ($plan['write'] as $rel) {
            $tmp = "$site/$rel.lou-new";
            Fs::ensureDir(dirname($tmp));
            if (!copy("$from/$rel", $tmp) || !rename($tmp, "$site/$rel")) {
                @unlink($tmp);
                throw new LouException("Cannot update site/$rel");
            }
        }
        foreach ($plan['delete'] as $rel) {
            unlink("$site/$rel");
        }
        Fs::writeJson($manifestFile, $plan['next']);
        return ['updated' => $plan['write'], 'deleted' => $plan['delete'], 'skipped' => $plan['skipped']];
    }

    /**
     * What bringing $site's templates and assets up to date with $from would do, without doing it.
     * A file is written or deleted only when the copy in $site is still what the last deploy put there
     * ($last: path => sha1); a file changed since is skipped. Editor uploads are never touched.
     * @param array<string, string> $last
     * @return array{write: list<string>, delete: list<string>, skipped: list<string>, next: array<string, string>}
     *         next: the manifest after doing it
     */
    public static function planDesign(string $from, string $site, array $last): array
    {
        $repo = self::designHashes($from);
        $server = self::designHashes($site);
        $plan = ['write' => [], 'delete' => [], 'skipped' => [], 'next' => []];
        foreach ($repo as $rel => $hash) {
            $here = $server[$rel] ?? null;
            if ($here === $hash) {
                $plan['next'][$rel] = $hash;
            } elseif ($here === null || ($last[$rel] ?? null) === $here) {
                $plan['write'][] = $rel;
                $plan['next'][$rel] = $hash;
            } else {
                if (isset($last[$rel])) {
                    $plan['next'][$rel] = $last[$rel];
                }
                $plan['skipped'][] = $rel;
            }
        }
        foreach ($last as $rel => $hash) {
            if (isset($repo[$rel]) || !isset($server[$rel])) {
                continue;
            }
            if ($server[$rel] === $hash) {
                $plan['delete'][] = $rel;
            } else {
                $plan['skipped'][] = $rel;
            }
        }
        return $plan;
    }

    /** @return array<string, string> site-relative path => sha1, for the DESIGN folders minus KEEP */
    public static function designHashes(string $site): array
    {
        $out = [];
        foreach (self::DESIGN as $dir) {
            foreach (Fs::files("$site/$dir") as $rel) {
                $rel = "$dir/$rel";
                foreach (self::KEEP as $keep) {
                    if (str_starts_with($rel, "$keep/")) {
                        continue 2;
                    }
                }
                if (!str_ends_with($rel, '.lou-new')) {
                    $out[$rel] = (string) sha1_file("$site/$rel");
                }
            }
        }
        ksort($out);
        return $out;
    }

    private function swap(string $new, string $dest): void
    {
        if (!file_exists($dest)) {
            if (!rename($new, $dest)) {
                throw new LouException("Cannot move $new to $dest");
            }
            return;
        }
        $old = $dest . '.lou-old';
        is_dir($old) ? Fs::removeDir($old) : @unlink($old);
        if (!rename($dest, $old)) {
            throw new LouException("Cannot move $dest out of the way");
        }
        if (!rename($new, $dest)) {
            rename($old, $dest);
            throw new LouException("Cannot move $new to $dest");
        }
        is_dir($old) ? Fs::removeDir($old) : unlink($old);
    }
}
