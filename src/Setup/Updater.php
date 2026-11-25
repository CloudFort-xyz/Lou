<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\App;
use Lou\Http\CurlHttpClient;
use Lou\Http\HttpClient;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * Updates an install from a release zip, without Git: Admin → Updates and `php lou update`.
 *
 * The zip comes from the GitHub releases (checked only when someone asks, Lou never phones home by itself) or
 * is uploaded. It is verified (Release::extract), then only the code is swapped in by the Deployer, the same way
 * `lou deploy` does it: site/, var/, .env.php and the live build are never touched. The new code then needs one
 * rebuild (stubs and the admin's script and styles), which runs in a fresh request or process, never in the one
 * that still has the old classes loaded.
 */
final class Updater
{
    public const REPO = 'CloudFort-xyz/Lou';
    private const MAX_DOWNLOAD = 64 * 1024 * 1024;

    private readonly HttpClient $http;

    /** @param string|false|null $publicKey null: this install's release-key.pub, false: don't check signatures */
    public function __construct(
        private readonly App $app,
        ?HttpClient $http = null,
        private readonly string|false|null $publicKey = null,
    ) {
        $this->http = $http ?? new CurlHttpClient(60, true);
    }

    public function repo(): string
    {
        $repo = (string) ($this->app->config['updates']['repo'] ?? self::REPO);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new LouException("updates.repo must look like owner/name, not '$repo'");
        }
        return $repo;
    }

    /** Why this install can't update itself from a zip, or null when it can. */
    public function blocked(): ?string
    {
        if (is_dir($this->app->root . '/.git')) {
            return 'This install is a Git checkout. Update it with git (or php lou deploy from your clone) instead.';
        }
        $from = $this->app->varDir . '/deployed-from.json';
        $source = is_file($from) ? (string) (Fs::readJson($from)['source'] ?? '') : '';
        if ($source !== '' && is_file("$source/lou")) {
            return "This install is deployed from the Git clone in $source (php lou deploy). Update that clone instead: "
                . "a zip update here would be overwritten by the next deploy. Delete var/deployed-from.json to switch to zip updates.";
        }
        return null;
    }

    /**
     * Whether this install may install release zips without a valid signature (after a confirmation).
     * Only .env.php can turn it on: it needs FTP/SSH access, so a stolen admin login can't.
     */
    public function unsignedAllowed(): bool
    {
        return \Lou\Env::load($this->app)->get('allow_unsigned_updates') === true;
    }

    /** @return array{version: string, signature: string} see Release::inspect() */
    public function inspect(string $zipFile): array
    {
        return Release::inspect($zipFile, $this->publicKey);
    }

    public function signaturesChecked(): bool
    {
        return ($this->publicKey === null ? Release::trustedKey() : ($this->publicKey ?: null)) !== null;
    }

    /**
     * Asks GitHub for the latest release and remembers the answer in var/update-check.json.
     * @return array{current: string, version: string, newer: bool, url: string, page: string, published: string, checked: string}
     */
    public function check(): array
    {
        $repo = $this->repo();
        $res = $this->http->request('GET', "https://api.github.com/repos/$repo/releases/latest", [
            'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28',
        ]);
        if ($res['status'] === 404) {
            throw new LouException("$repo has no releases yet");
        }
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data) || !is_string($data['tag_name'] ?? null)) {
            throw new LouException("GitHub did not answer as expected (HTTP {$res['status']})");
        }
        $version = ltrim($data['tag_name'], 'v');
        $url = '';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === "lou-$version.zip" && is_string($asset['browser_download_url'] ?? null)) {
                $url = $asset['browser_download_url'];
            }
        }
        if (!str_starts_with($url, "https://github.com/$repo/releases/download/")) {
            throw new LouException("The latest release ($version) has no lou-$version.zip attached");
        }
        $result = [
            'current'   => App::VERSION,
            'version'   => $version,
            'newer'     => version_compare($version, App::VERSION, '>'),
            'url'       => $url,
            'page'      => is_string($data['html_url'] ?? null) ? $data['html_url'] : "https://github.com/$repo/releases",
            'published' => is_string($data['published_at'] ?? null) ? $data['published_at'] : '',
            'checked'   => gmdate('c'),
        ];
        Fs::writeJson($this->checkFile(), $result);
        return $result;
    }

    /** @return array<string, mixed>|null the last check, if any */
    public function lastCheck(): ?array
    {
        return is_file($this->checkFile()) ? Fs::readJson($this->checkFile()) : null;
    }

    /** Downloads the release found by the last check and returns the zip's path in var/updates/. */
    public function download(): string
    {
        $last = $this->lastCheck();
        $url = is_array($last) ? (string) ($last['url'] ?? '') : '';
        if (!str_starts_with($url, 'https://github.com/' . $this->repo() . '/releases/download/')) {
            throw new LouException('Check for updates first');
        }
        $res = $this->http->request('GET', $url, ['Accept' => 'application/octet-stream']);
        if ($res['status'] !== 200 || $res['body'] === '' || strlen($res['body']) > self::MAX_DOWNLOAD) {
            throw new LouException("Downloading the release failed (HTTP {$res['status']})");
        }
        $file = $this->app->varDir . '/updates/lou-' . preg_replace('/[^0-9A-Za-z.-]/', '', (string) $last['version']) . '.zip';
        Fs::write($file, $res['body']);
        return $file;
    }

    /**
     * Verifies a release zip and swaps its code into this install.
     * @param bool $force also allow an older version
     * @param bool $unsigned skip the signature check (only when unsignedAllowed() and the person confirmed)
     * @return array{from: string, to: string, signed: bool}
     */
    public function apply(string $zipFile, bool $force = false, bool $unsigned = false): array
    {
        if ($unsigned && !$this->unsignedAllowed()) {
            throw new LouException("Unsigned releases are not allowed on this install (set 'allow_unsigned_updates' => true in .env.php)");
        }
        if (($why = $this->blocked()) !== null) {
            throw new LouException($why);
        }
        $dir = $this->app->varDir . '/updates/.unpacked-' . bin2hex(random_bytes(4));
        $release = Release::extract($zipFile, $dir, $unsigned ? false : $this->publicKey);
        try {
            if (version_compare(PHP_VERSION, (string) $release['php'], '<')) {
                throw new LouException("Lou {$release['version']} needs PHP {$release['php']} or newer, this is " . PHP_VERSION);
            }
            if (!$force && version_compare($release['version'], App::VERSION, '<')) {
                throw new LouException("This zip is Lou {$release['version']}, older than the installed " . App::VERSION);
            }
            (new Deployer("$dir/" . Release::DIR))->deploy($this->app->root);
        } finally {
            Fs::removeDir($dir);
            @rmdir(dirname($dir));
        }
        $result = ['from' => App::VERSION, 'to' => (string) $release['version'], 'signed' => (bool) $release['signed']];
        Fs::writeJson($this->app->varDir . '/updated.json', $result + ['at' => gmdate('c')]);
        return $result;
    }

    /** True when the live build was made by another Lou version than the code on disk (after an update). */
    public static function needsRebuild(App $app): bool
    {
        $live = $app->varDir . '/live.json';
        return is_file($live) && (Fs::readJson($live)['lou'] ?? null) !== App::VERSION;
    }

    private function checkFile(): string
    {
        return $this->app->varDir . '/update-check.json';
    }
}
