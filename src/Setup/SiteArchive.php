<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\App;
use Lou\Backup\Backup;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * Unpacks a site from a zip into a site/ folder. Two kinds are accepted:
 *
 * - a Lou backup (manifest.json + site/ + lou.sqlite), checked like `lou restore` does;
 * - any zip that holds a site folder: the shallowest folder with config.php and content/ is taken, so a
 *   GitHub "Download ZIP" of a client repository (kner.hu-lou-main/site/…) works as it is.
 */
final class SiteArchive
{
    private const MAX_FILES = 20000;
    private const MAX_BYTES = 512 * 1024 * 1024;

    /**
     * @param string $dest the site/ folder to create (must not exist)
     * @return array{kind: 'backup'|'site', database: ?string} database: the backup's SQLite file, left in $scratch
     */
    public static function extract(string $zipFile, string $dest, string $scratch, ?string $password = null): array
    {
        if (file_exists($dest)) {
            throw new LouException("$dest already exists");
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::RDONLY) !== true) {
            throw new LouException('The uploaded file is not a zip');
        }
        $isBackup = $zip->locateName('manifest.json') !== false && $zip->locateName('site/config.php') !== false;
        if ($isBackup) {
            $zip->close();
            // Backup needs an App only for its paths; $scratch stands in for the install root.
            $tmp = "$scratch/backup";
            $manifest = (new Backup(new App($scratch)))->extractAndVerify($zipFile, $password, $tmp);
            Fs::ensureDir(dirname($dest));
            if (!rename("$tmp/site", $dest)) {
                throw new LouException("Cannot move the site into $dest");
            }
            return ['kind' => 'backup', 'database' => isset($manifest['files']['lou.sqlite']) ? "$tmp/lou.sqlite" : null];
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new LouException('The zip has too many files');
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[$i] = (string) $zip->getNameIndex($i);
            }
            $prefix = null;
            foreach ($names as $name) {
                if (preg_match('#^((?:[^/]+/)*)config\.php$#', $name, $m)
                    && in_array($m[1] . 'content/', array_map(static fn ($n) => substr($n, 0, strlen($m[1]) + 8), $names), true)
                    && ($prefix === null || strlen($m[1]) < strlen($prefix))) {
                    $prefix = $m[1];
                }
            }
            if ($prefix === null) {
                throw new LouException('No Lou site found in the zip: it needs a folder with config.php and content/ (usually site/)');
            }

            $total = 0;
            Fs::ensureDir($dest);
            foreach ($names as $i => $name) {
                if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                    continue;
                }
                $rel = substr($name, strlen($prefix));
                if (str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, '\\') || str_contains($rel, "\0")) {
                    throw new LouException("Unsafe path in the zip: $name");
                }
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
                if ($total > self::MAX_BYTES) {
                    throw new LouException('The site in the zip is too large');
                }
                $data = $zip->getFromIndex($i);
                if (!is_string($data)) {
                    throw new LouException("Cannot read $name from the zip");
                }
                Fs::write("$dest/$rel", $data);
            }
            return ['kind' => 'site', 'database' => null];
        } catch (\Throwable $e) {
            Fs::removeDir($dest);
            throw $e;
        } finally {
            $zip->close();
        }
    }
}
