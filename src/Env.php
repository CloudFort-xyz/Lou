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
 */
final class Env
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values, private readonly string $keyFile)
    {
    }

    public static function load(App $app): self
    {
        $file = $app->envFile();
        $values = is_file($file) ? require $file : [];
        if (!is_array($values)) {
            throw new LouException('.env.php must return an array');
        }
        return new self($values, $app->varDir . '/app.key');
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
