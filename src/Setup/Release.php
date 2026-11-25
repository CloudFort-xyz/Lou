<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\App;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * Builds and checks release zips: the way to install and update Lou without Git.
 *
 *   lou-0.2.0.zip
 *   └── lou/
 *       ├── lou, src/, stubs/, vendor-bundled/, .env.example.php    the code (what an update replaces)
 *       ├── starters/example/        starter sites the installer can copy to site/
 *       ├── public/index.php         the installer (until the first build replaces public/)
 *       ├── public/install.php
 *       ├── release.json             version, minimum PHP and the SHA-256 of every file above
 *       └── release.sig              Ed25519 signature of release.json (when the release was signed)
 *
 * A release never contains site/, var/ or .env.php, so unpacking a newer zip over an install (over FTP, say)
 * can't overwrite anyone's content.
 */
final class Release
{
    public const DIR = 'lou';
    /** The public key that update zips must be signed with, base64. Missing = signatures are not checked. */
    public const KEY_FILE = __DIR__ . '/release-key.pub';
    public const MIN_PHP = '8.3.0';
    private const MAX_FILES = 5000;
    private const MAX_BYTES = 64 * 1024 * 1024;

    /** @param string|false|null $publicKey the key installs trust (null: release-key.pub, false: none) */
    public function __construct(private readonly string $source, private readonly string|false|null $publicKey = null)
    {
    }

    /**
     * Writes lou-<version>.zip (+ .sha256) into $outDir and returns the zip's path.
     * @param string|null $version   overrides App::VERSION in the packaged src/App.php (the release workflow passes the tag)
     * @param string|null $secretKey base64 Ed25519 secret key (sodium_crypto_sign_keypair format) to sign with
     * @param bool        $unsigned  build without a signature on purpose (CI smoke tests): installs that check
     *                               signatures will refuse the zip
     */
    public function build(string $outDir, ?string $version = null, ?string $secretKey = null, bool $unsigned = false): string
    {
        $src = rtrim($this->source, '/');
        if (!is_file("$src/lou") || !is_file("$src/src/App.php")) {
            throw new LouException("$src is not a Lou checkout");
        }
        $version ??= App::VERSION;
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version)) {
            throw new LouException("'$version' is not a version like 1.2.3");
        }
        $trusted = $this->publicKey === null ? self::trustedKey() : ($this->publicKey ?: null);
        if ($secretKey === null && $trusted !== null && !$unsigned) {
            throw new LouException('Installs check update signatures (' . basename(self::KEY_FILE) . ' exists), so a release must be signed: set LOU_SIGNING_KEY');
        }
        if ($secretKey !== null && $trusted !== null
            && base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::secret($secretKey))) !== $trusted) {
            throw new LouException('LOU_SIGNING_KEY does not match ' . basename(self::KEY_FILE) . ': installs would refuse this release');
        }

        $files = [];   // path inside lou/ => contents
        foreach (Deployer::CODE as $item) {
            if (is_dir("$src/$item")) {
                foreach (Fs::files("$src/$item") as $rel) {
                    $files["$item/$rel"] = (string) file_get_contents("$src/$item/$rel");
                }
            } elseif (is_file("$src/$item")) {
                $files[$item] = (string) file_get_contents("$src/$item");
            }
        }
        $files['src/App.php'] = (string) preg_replace("/public const VERSION = '[^']*';/", "public const VERSION = '$version';", $files['src/App.php'], 1);
        foreach (Fs::files("$src/site") as $rel) {
            $files["starters/example/$rel"] = (string) file_get_contents("$src/site/$rel");
        }
        foreach (['index.php', 'install.php'] as $f) {
            $files["public/$f"] = (string) file_get_contents(__DIR__ . "/stubs/$f");
        }
        foreach (['README.md', 'docs/install.md'] as $doc) {
            if (is_file("$src/$doc")) {
                $files[basename($doc) === 'install.md' ? 'INSTALL.md' : $doc] = (string) file_get_contents("$src/$doc");
            }
        }
        ksort($files);

        $hashes = array_map(static fn (string $c): string => hash('sha256', $c), $files);
        $manifest = json_encode([
            'name'    => 'lou',
            'version' => $version,
            'php'     => self::MIN_PHP,
            'built'   => gmdate('c'),
            'files'   => $hashes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        Fs::ensureDir($outDir);
        $file = rtrim($outDir, '/') . "/lou-$version.zip";
        @unlink($file);
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new LouException("Cannot create $file");
        }
        foreach ($files as $rel => $contents) {
            $zip->addFromString(self::DIR . "/$rel", $contents);
            // rw-r--r-- (the CLI rwxr-xr-x), so unpacking never leaves world-writable files behind
            $zip->setExternalAttributesName(self::DIR . "/$rel", \ZipArchive::OPSYS_UNIX, ($rel === 'lou' ? 0100755 : 0100644) << 16);
        }
        $zip->addFromString(self::DIR . '/release.json', $manifest);
        $zip->setExternalAttributesName(self::DIR . '/release.json', \ZipArchive::OPSYS_UNIX, 0100644 << 16);
        if ($secretKey !== null) {
            $zip->addFromString(self::DIR . '/release.sig', base64_encode(sodium_crypto_sign_detached($manifest, self::secret($secretKey))) . "\n");
        }
        if (!$zip->close()) {
            throw new LouException("Writing $file failed");
        }
        file_put_contents("$file.sha256", hash_file('sha256', $file) . '  ' . basename($file) . "\n");
        return $file;
    }

    /**
     * Unpacks a release zip into $into/lou after checking it: the signature (when this install has a key),
     * every file against release.json, nothing outside lou/, nothing unlisted. Returns release.json.
     * @param string|false|null $publicKey null: this install's release-key.pub, false: don't check signatures
     * @return array{name: string, version: string, php: string, files: array<string, string>, signed: bool}
     */
    public static function extract(string $zipFile, string $into, string|false|null $publicKey = null): array
    {
        $publicKey = $publicKey === null ? self::trustedKey() : ($publicKey ?: null);
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::RDONLY) !== true) {
            throw new LouException('This is not a zip file');
        }
        try {
            $raw = $zip->getFromName(self::DIR . '/release.json');
            $manifest = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($manifest) || ($manifest['name'] ?? '') !== 'lou' || !is_array($manifest['files'] ?? null) || !is_string($manifest['version'] ?? null)) {
                throw new LouException('This is not a Lou release zip (no lou/release.json). Download lou-<version>.zip from the releases page.');
            }
            $sig = $zip->getFromName(self::DIR . '/release.sig');
            $signed = false;
            if ($publicKey !== null) {
                if (!is_string($sig) || $sig === '') {
                    throw new LouException('This release is not signed, and this install only accepts signed releases');
                }
                if (!self::verify((string) $raw, trim($sig), $publicKey)) {
                    throw new LouException('The release signature is not valid: the zip was changed or did not come from the Lou releases');
                }
                $signed = true;
            }
            if ($zip->numFiles > self::MAX_FILES) {
                throw new LouException('The zip has too many files');
            }

            $seen = [];
            $total = 0;
            Fs::ensureDir($into);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (!str_starts_with($name, self::DIR . '/') || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, "\0")) {
                    throw new LouException("Unexpected file in the release: $name");
                }
                $rel = substr($name, strlen(self::DIR) + 1);
                if ($rel === 'release.json' || $rel === 'release.sig') {
                    continue;
                }
                $want = $manifest['files'][$rel] ?? null;
                if (!is_string($want)) {
                    throw new LouException("File not listed in release.json: $rel");
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_BYTES) {
                    throw new LouException('The release is too large');
                }
                $data = $zip->getFromIndex($i);
                if (!is_string($data) || !hash_equals($want, hash('sha256', $data))) {
                    throw new LouException("Checksum mismatch: $rel");
                }
                Fs::write("$into/" . self::DIR . "/$rel", $data);
                $seen[$rel] = true;
            }
            foreach (array_keys($manifest['files']) as $rel) {
                if (!isset($seen[$rel])) {
                    throw new LouException("Missing from the zip: $rel");
                }
            }
            @chmod("$into/" . self::DIR . '/lou', 0755);
            return $manifest + ['php' => self::MIN_PHP, 'signed' => $signed];
        } catch (\Throwable $e) {
            Fs::removeDir($into);
            throw $e;
        } finally {
            $zip->close();
        }
    }

    /** The public key shipped with this install, or null when updates are not signature-checked. */
    /**
     * Reads a release zip's version and signature state without unpacking it.
     * signature: 'valid', 'missing' (no release.sig), 'invalid' (doesn't match), or 'unchecked' (no key to check against).
     * @param string|false|null $publicKey as in extract()
     * @return array{version: string, signature: string}
     */
    public static function inspect(string $zipFile, string|false|null $publicKey = null): array
    {
        $publicKey = $publicKey === null ? self::trustedKey() : ($publicKey ?: null);
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::RDONLY) !== true) {
            throw new LouException('This is not a zip file');
        }
        try {
            $raw = $zip->getFromName(self::DIR . '/release.json');
            $manifest = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($manifest) || ($manifest['name'] ?? '') !== 'lou' || !is_string($manifest['version'] ?? null)) {
                throw new LouException('This is not a Lou release zip (no lou/release.json). Download lou-<version>.zip from the releases page.');
            }
            $sig = $zip->getFromName(self::DIR . '/release.sig');
            $signature = match (true) {
                $publicKey === null                   => 'unchecked',
                !is_string($sig) || trim($sig) === '' => 'missing',
                self::verify((string) $raw, trim($sig), $publicKey) => 'valid',
                default                               => 'invalid',
            };
            return ['version' => $manifest['version'], 'signature' => $signature];
        } finally {
            $zip->close();
        }
    }

    public static function trustedKey(): ?string
    {
        if (!is_file(self::KEY_FILE)) {
            return null;
        }
        $key = trim((string) file_get_contents(self::KEY_FILE));
        return $key === '' ? null : $key;
    }

    public static function verify(string $message, string $signatureB64, string $publicKeyB64): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new LouException('Checking release signatures needs the PHP sodium extension');
        }
        $sig = base64_decode($signatureB64, true);
        $key = base64_decode($publicKeyB64, true);
        if ($sig === false || $key === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    /** @return array{secret: string, public: string} a new signing key pair, base64 */
    public static function keyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    private static function secret(string $b64): string
    {
        $key = base64_decode(trim($b64), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new LouException('LOU_SIGNING_KEY is not a signing key made by php lou release-key');
        }
        return $key;
    }
}
