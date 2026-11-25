<?php

declare(strict_types=1);

namespace Lou\Backup;

use Lou\App;
use Lou\Bridge\PathPolicy;
use Lou\Content\Workspace;
use Lou\LouException;
use Lou\Support\Fs;
use Lou\Support\Str;

/**
 * Single-file backups: one .zip with site/, a SQLite snapshot and a manifest.
 *
 * File names are stored as UTF-8 (ZipArchive sets the UTF-8 flag), contents are binary-safe.
 * Secrets (.env.php) are only included when a password is given, and then every entry is AES-256 encrypted.
 */
final class Backup
{
    public const FORMAT = 1;

    public function __construct(private readonly App $app)
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new LouException('The PHP zip extension is required for backups');
        }
    }

    public function backupDir(): string
    {
        return $this->app->varDir . '/backups';
    }

    /** @return string path of the created backup */
    public function create(?string $file = null, ?string $password = null, ?int $keep = null): string
    {
        $slug = Str::slug((string) $this->app->config['name'], 'site');
        $file ??= $this->backupDir() . '/lou-backup-' . $slug . '-' . gmdate('Ymd-His') . '.zip';
        Fs::ensureDir(dirname($file));

        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new LouException("Cannot create backup file: $file");
        }

        $checksums = [];
        foreach (Fs::files($this->app->siteDir) as $rel) {
            $path = $this->app->siteDir . '/' . $rel;
            $zip->addFile($path, 'site/' . $rel);
            $checksums['site/' . $rel] = hash_file('sha256', $path);
        }

        $snapshot = null;
        if (is_file($this->app->databaseFile())) {
            $snapshot = $this->app->varDir . '/.backup-' . bin2hex(random_bytes(4)) . '.sqlite';
            $pdo = new \PDO('sqlite:' . $this->app->databaseFile());
            $pdo->exec('VACUUM INTO ' . $pdo->quote($snapshot));
            $pdo = null;
            $zip->addFile($snapshot, 'lou.sqlite');
            $checksums['lou.sqlite'] = hash_file('sha256', $snapshot);
        }

        if ($password !== null && is_file($this->app->envFile())) {
            $zip->addFile($this->app->envFile(), '.env.php');
            $checksums['.env.php'] = hash_file('sha256', $this->app->envFile());
        }

        $manifest = [
            'format'    => self::FORMAT,
            'lou'       => App::VERSION,
            'created'   => gmdate('c'),
            'site'      => $this->app->config['name'],
            'languages' => $this->app->config['languages'],
            'encrypted' => $password !== null,
            'files'     => $checksums,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($password !== null) {
            $zip->setPassword($password);
            foreach (array_keys($checksums) as $name) {
                $zip->setEncryptionName($name, \ZipArchive::EM_AES_256, $password);
            }
        }
        $ok = $zip->close();
        if ($snapshot !== null) {
            @unlink($snapshot);
        }
        if (!$ok) {
            throw new LouException("Writing the backup failed: $file");
        }

        if ($keep !== null) {
            $this->prune($keep);
        }
        return $file;
    }

    /**
     * Restore a backup over site/ (and the database unless $withDatabase is false).
     * The current state is backed up first. Returns the path of that safety backup.
     *
     * (When the admin exists, restores go to staging for preview + approval first.)
     */
    public function restore(string $file, ?string $password = null, bool $withDatabase = true): string
    {
        $tmp = $this->app->varDir . '/.restore-' . bin2hex(random_bytes(4));
        $manifest = $this->extractAndVerify($file, $password, $tmp);

        try {
            $safety = $this->create($this->backupDir() . '/before-restore-' . gmdate('Ymd-His') . '.zip');

            $old = $this->app->siteDir . '.old-' . bin2hex(random_bytes(3));
            if (is_dir($this->app->siteDir) && !rename($this->app->siteDir, $old)) {
                throw new LouException('Cannot move the current site/ out of the way');
            }
            if (!rename("$tmp/site", $this->app->siteDir)) {
                rename($old, $this->app->siteDir);
                throw new LouException('Cannot move the restored site/ into place');
            }
            Fs::removeDir($old);

            if ($withDatabase && isset($manifest['files']['lou.sqlite'])) {
                Fs::ensureDir($this->app->varDir);
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($this->app->databaseFile() . $suffix);
                }
                rename("$tmp/lou.sqlite", $this->app->databaseFile());
            }
            // the restored content defines the new baseline for redirect detection
            @unlink($this->app->varDir . '/manifest.json');
            return $safety;
        } finally {
            Fs::removeDir($tmp);
        }
    }

    /**
     * The admin's restore: the backup's site/ goes onto staging, so it is previewed and published through Review
     * like any other change (the CLI's restore() replaces site/ directly). A backup is a zip anyone could have
     * made, so its templates and config.php get the same checks as an assistant's, and it may not contain
     * dotfiles (an assets/.htaccess would be copied into the web root) or PHP outside templates/ and config.php.
     * The database, which is not part of the site, is restored right away unless $withDatabase is false.
     *
     * @return array{safety: string, files: list<string>} the safety backup and the files that differ from the live site
     */
    public function restoreToStaging(string $file, ?string $password, bool $withDatabase, Workspace $staging): array
    {
        if ($staging->exists()) {
            throw new LouException('Something is waiting on Review. Publish or reject it first, then restore the backup.');
        }
        $tmp = $this->app->varDir . '/.restore-' . bin2hex(random_bytes(4));
        $manifest = $this->extractAndVerify($file, $password, $tmp);
        try {
            $problems = self::check("$tmp/site");
            if ($problems) {
                throw new LouException('This backup was not restored, because it contains files Lou does not accept:', $problems);
            }
            $safety = $this->create($this->backupDir() . '/before-restore-' . gmdate('Ymd-His') . '.zip');
            $staging->open();
            Fs::removeDir($staging->siteDir());
            if (!rename("$tmp/site", $staging->siteDir())) {
                $staging->discard();
                throw new LouException('Cannot move the restored site onto staging');
            }
            $staging->touch();
            $files = $staging->changedFiles();
            if ($files === []) {
                $staging->discard();
            }
            if ($withDatabase && isset($manifest['files']['lou.sqlite'])) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($this->app->databaseFile() . $suffix);
                }
                rename("$tmp/lou.sqlite", $this->app->databaseFile());
            }
            return ['safety' => $safety, 'files' => $files];
        } finally {
            Fs::removeDir($tmp);
        }
    }

    /** @return list<string> what an uploaded site may not contain */
    private static function check(string $siteDir): array
    {
        $problems = [];
        foreach (Fs::files($siteDir) as $rel) {
            if (preg_match('#(^|/)\.#', $rel)) {
                $problems[] = "$rel: hidden files (starting with a dot) are not allowed";
            } elseif (str_ends_with(strtolower($rel), '.php') && !str_starts_with($rel, 'templates/') && $rel !== 'config.php') {
                $problems[] = "$rel: PHP files may only be in templates/ or be config.php";
            } elseif (str_starts_with($rel, 'templates/') || $rel === 'config.php') {
                array_push($problems, ...PathPolicy::checkContent($rel, (string) file_get_contents("$siteDir/$rel")));
            }
        }
        return $problems;
    }

    /** @return array<string, mixed> the manifest */
    public function extractAndVerify(string $file, ?string $password, string $into): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new LouException("Cannot open backup: $file");
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
                throw new LouException('Not a Lou backup (missing or unknown manifest.json)');
            }
            if (!empty($manifest['encrypted'])) {
                if ($password === null) {
                    throw new LouException('This backup is encrypted: a password is required');
                }
                $zip->setPassword($password);
            }
            $errors = [];
            Fs::ensureDir($into);
            foreach ($manifest['files'] as $name => $sha) {
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    throw new LouException("Unsafe path in backup: $name");
                }
                $data = $zip->getFromName($name);
                if ($data === false) {
                    $errors[] = "Missing or unreadable: $name" . (!empty($manifest['encrypted']) ? ' (wrong password?)' : '');
                    continue;
                }
                if (hash('sha256', $data) !== $sha) {
                    $errors[] = "Checksum mismatch: $name";
                    continue;
                }
                Fs::write("$into/$name", $data);
            }
            if ($errors) {
                Fs::removeDir($into);
                throw new LouException('Backup verification failed:', $errors);
            }
            if (!is_dir("$into/site")) {
                Fs::removeDir($into);
                throw new LouException('Backup contains no site/ directory');
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function prune(int $keep): void
    {
        $all = glob($this->backupDir() . '/lou-backup-*.zip') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - max(1, $keep))) as $f) {
            unlink($f);
        }
    }
}
