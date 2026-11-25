<?php

declare(strict_types=1);

namespace Lou\Support;

use Lou\LouException;

final class Fs
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new LouException("Cannot create directory: $dir");
        }
    }

    public static function write(string $file, string $contents): void
    {
        self::ensureDir(dirname($file));
        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            throw new LouException("Cannot write file: $file");
        }
    }

    public static function readJson(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new LouException("Invalid JSON in $file: " . json_last_error_msg());
        }
        return $data;
    }

    public static function writeJson(string $file, array $data): void
    {
        self::write($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** Recursively copy $from into $to (merging into existing dirs). */
    public static function copyDir(string $from, string $to): void
    {
        self::ensureDir($to);
        foreach (self::files($from) as $rel) {
            self::ensureDir(dirname("$to/$rel"));
            if (!copy("$from/$rel", "$to/$rel")) {
                throw new LouException("Cannot copy $from/$rel");
            }
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /**
     * All files under $dir as sorted relative paths (dotfiles included).
     * @return list<string>
     */
    public static function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = substr($f->getPathname(), strlen($dir) + 1);
            }
        }
        sort($out);
        return $out;
    }
}
