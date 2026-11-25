<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\Admin\Users;
use Lou\App;
use Lou\Build\Builder;
use Lou\Build\Output;
use Lou\Env;
use Lou\LouException;
use Lou\Mail\MailSettings;
use Lou\Support\Fs;

/**
 * First-time setup of an install unpacked from a release zip: what the web installer (InstallerApp) runs.
 *
 * It seeds site/ (from a starter or an uploaded zip), saves the site address, where the site is built and the
 * email settings, builds the site, and creates the first admin. The admin comes last: once a user exists the
 * installer is locked, so a failed step can simply be tried again.
 *
 * Proof of ownership: the installer is reachable by anyone until it has run, and an uploaded site runs PHP
 * templates at build time. So it asks for a setup key that it writes to var/install-key.txt, which only someone
 * with access to the files (FTP, the hosting panel's file manager) can read.
 */
final class Installer
{
    public function __construct(
        private readonly string $root,
        private readonly ?Requirements $requirements = null,
    ) {
    }

    public function installed(): bool
    {
        return (new Users($this->root . '/var/users.json'))->all() !== [];
    }

    public function keyFile(): string
    {
        return $this->root . '/var/install-key.txt';
    }

    /** The setup key, created on first use. */
    public function key(): string
    {
        $file = $this->keyFile();
        if (!is_file($file)) {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $key = '';
            for ($i = 0; $i < 16; $i++) {
                $key .= $alphabet[random_int(0, 31)] . ($i % 4 === 3 && $i < 15 ? '-' : '');
            }
            Fs::write($file, $key . "\n");
            @chmod($file, 0600);
        }
        return trim((string) file_get_contents($file));
    }

    public function checkKey(string $typed): bool
    {
        $norm = static fn (string $k): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $k));
        return is_file($this->keyFile()) && $typed !== '' && hash_equals($norm($this->key()), $norm($typed));
    }

    /**
     * Starter sites that can become site/: id => display name. "existing" when site/ is already there.
     * @return array<string, string>
     */
    public function starters(): array
    {
        if (is_file($this->root . '/site/config.php')) {
            return ['existing' => 'The site already in site/'];
        }
        $out = [];
        foreach (glob($this->root . '/starters/*/config.php') ?: [] as $config) {
            $id = basename(dirname($config));
            $name = $id;
            try {
                $c = (static fn (string $f) => require $f)($config);
                $name = is_array($c) && is_string($c['name'] ?? null) ? $c['name'] : $id;
            } catch (\Throwable) {
            }
            $out[$id] = $name;
        }
        return $out;
    }

    /** True when the site is served from the folder the installer runs in, not from lou/public. */
    public function buildsIntoDocroot(string $docroot): bool
    {
        $public = realpath($this->root . '/public');
        return $public === false || $public !== realpath($docroot);
    }

    /**
     * Requirement checks for this PHP and these folders.
     * @return list<array{level: string, label: string, detail: string}>
     */
    public function checks(string $docroot): array
    {
        $r = ($this->requirements ?? new Requirements($this->root))->php();
        $dirs = ['install folder' => $this->root, 'var/' => $this->root . '/var'];
        if ($this->buildsIntoDocroot($docroot)) {
            $dirs['web root'] = $docroot;
        }
        foreach ($dirs as $label => $dir) {
            $probe = is_dir($dir) ? $dir : dirname($dir);
            $r[] = is_writable($probe)
                ? ['level' => Requirements::OK, 'label' => "writable $label", 'detail' => $dir]
                : ['level' => Requirements::FAIL, 'label' => "writable $label", 'detail' => "$dir must be writable by PHP (set the folder to 755 or ask the host)"];
        }
        return $r;
    }

    /**
     * @param array<string, string> $in        the installer form
     * @param string|null           $siteZip   an uploaded site zip (starter = upload)
     * @param string                $docroot   the folder the installer was served from
     * @return array{warnings: list<string>, output: string, site: string}
     */
    public function install(array $in, ?string $siteZip, string $docroot): array
    {
        if ($this->installed()) {
            throw new LouException('Lou is already installed here');
        }
        $get = static fn (string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';
        if (!$this->checkKey($get('key'))) {
            throw new LouException('The setup key is not right. Open var/install-key.txt in the lou folder (FTP or the file manager) and copy it from there.');
        }
        if (!Requirements::passed($this->checks($docroot))) {
            throw new LouException('Some requirements are missing (marked FAIL above)');
        }

        // validate everything before changing anything
        $baseUrl = App::normalizeBaseUrl($get('base_url'));
        $user = trim($get('admin_user'));
        Users::validate($user, $get('admin_password'), 'admin');
        if ($get('admin_password') !== $get('admin_password2')) {
            throw new LouException('The two passwords are not the same');
        }
        $mail = MailSettings::fromInput(static fn (string $k): string => $get("mail_$k"));
        $starter = $get('starter');
        $starters = $this->starters();
        if ($starter !== 'upload' && !isset($starters[$starter])) {
            throw new LouException('Choose a starter site');
        }
        if ($starter === 'upload' && $siteZip === null) {
            throw new LouException('Choose the site .zip to upload');
        }

        $siteDir = $this->root . '/site';
        $seeded = false;
        $scratch = $this->root . '/var/.install-' . bin2hex(random_bytes(4));
        $settingsFile = $this->root . '/var/settings.json';
        try {
            if ($starter !== 'existing') {
                if ($starter === 'upload') {
                    $r = SiteArchive::extract((string) $siteZip, $siteDir, $scratch, $get('backup_password') ?: null);
                    // baseline for later design deploys (lou deploy --site, or a zip in Admin → Updates)
                    Fs::writeJson($this->root . '/var/deploy-design.json', Deployer::designHashes($siteDir));
                    if ($r['database'] !== null) {
                        rename($r['database'], $this->root . '/var/lou.sqlite');
                    }
                } else {
                    Fs::copyDir($this->root . "/starters/$starter", $siteDir);
                }
                $seeded = true;
            }

            $settings = is_file($settingsFile) ? Fs::readJson($settingsFile) : [];
            $settings['base_url'] = $baseUrl;
            $settings['mail'] = $mail['mail'];
            if ($this->buildsIntoDocroot($docroot)) {
                $settings['output_dir'] = (string) realpath($docroot);
                $settings['output_swap'] = Output::CONTENTS;
            } else {
                unset($settings['output_dir'], $settings['output_swap']);
            }
            Fs::writeJson($settingsFile, $settings);
            $app = new App($this->root);
            if ($mail['smtp'] !== null) {
                Env::storeAdminSecret($app, 'smtp', $mail['smtp']);
            }

            $build = (new Builder($app))->build();
            if (!$this->buildsIntoDocroot($docroot)) {
                // the "previous build" was the installer itself: nothing to roll back to
                Fs::removeDir($this->root . "/var/builds/before-{$build['id']}");
            }
        } catch (\Throwable $e) {
            if ($seeded) {
                Fs::removeDir($siteDir);
                @unlink($this->root . '/var/lou.sqlite');
                @unlink($this->root . '/var/deploy-design.json');
            }
            throw $e;
        } finally {
            Fs::removeDir($scratch);
        }

        (new Users($this->root . '/var/users.json'))->add($user, $get('admin_password'), 'admin');
        @unlink($this->keyFile());
        Fs::writeJson($this->root . '/var/installed.json', ['lou' => App::VERSION, 'at' => gmdate('c'), 'by' => strtolower($user)]);
        return ['warnings' => $build['warnings'], 'output' => $build['output'], 'site' => $baseUrl];
    }
}
