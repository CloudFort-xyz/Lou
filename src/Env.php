<?php

declare(strict_types=1);

namespace Lou;

use Lou\Support\Arr;
use Lou\Support\Fs;

/**
 * Secrets from .env.php (outside the web root, never committed):
 *
 *   <?php return [
 *     'smtp'             => ['host' => 'mail.example.com', 'port' => 587, 'user' => '…', 'pass' => '…', 'secure' => 'tls'],
 *     'mailchimp_key'    => '…-us21',
 *     'mailerlite_key'   => '…',
 *     'turnstile_secret' => '…',
 *     'recaptcha_secret' => '…',
 *     'webhooks'         => ['zapier_contact' => 'https://hooks.zapier.com/…'],
 *     'webhook_secret'   => '…',   // optional: signs outgoing webhooks (X-Lou-Signature)
 *   ];
 *
 * SMTP can also be set in Admin → Settings. That is stored in var/secrets.json (mode 0600) and wins over .env.php.
 */
final class Env
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values, private readonly string $keyFile)
    {
    }

    /** Keys the admin may store in var/secrets.json. */
    public const ADMIN_KEYS = ['smtp'];

    public static function load(App $app): self
    {
        $file = $app->envFile();
        $values = is_file($file) ? require $file : [];
        if (!is_array($values)) {
            throw new LouException('.env.php must return an array');
        }
        return new self(array_replace($values, self::adminSecrets($app)), $app->varDir . '/app.key');
    }

    /** @return array<string, mixed> what the admin saved (only ADMIN_KEYS) */
    public static function adminSecrets(App $app): array
    {
        $file = $app->secretsFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? array_intersect_key($data, array_flip(self::ADMIN_KEYS)) : [];
    }

    /** Save one admin secret. The file is created owner-only (0600) before anything is written to it. */
    public static function storeAdminSecret(App $app, string $key, array $value): void
    {
        if (!in_array($key, self::ADMIN_KEYS, true)) {
            throw new LouException("'$key' cannot be set from the admin");
        }
        $data = [$key => $value] + self::adminSecrets($app);
        $file = $app->secretsFile();
        Fs::ensureDir(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) === false) {
                throw new LouException("Cannot write $file");
            }
        } finally {
            umask($old);
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new LouException("Cannot write $file");
        }
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->values, $path, $default);
    }

    public function require(string $path): mixed
    {
        return $this->get($path) ?? throw new LouException("Missing secret '$path' in .env.php");
    }

    /** Key for signing form tokens etc. Taken from .env.php 'app_key', or generated once into var/app.key. */
    public function appKey(): string
    {
        $key = $this->get('app_key');
        if (is_string($key) && strlen($key) >= 32) {
            return $key;
        }
        if (!is_file($this->keyFile)) {
            Fs::write($this->keyFile, bin2hex(random_bytes(32)));
            @chmod($this->keyFile, 0600);
        }
        return trim((string) file_get_contents($this->keyFile));
    }
}
