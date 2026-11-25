<?php

declare(strict_types=1);

namespace Lou;

/**
 * Holds the install paths and the merged site configuration.
 */
final class App
{
    public const VERSION = '0.1.0-dev';

    private const DEFAULTS = [
        'name'             => 'Lou site',
        'base_url'         => '',          // e.g. https://example.com (needed for sitemap + hreflang)
        'languages'        => ['en'],
        'default_language' => 'en',
        'prefix_default'   => false,       // false: default language at /, others at /<lang>/
        'output_dir'       => 'public',    // relative to the install root, or absolute
        'keep_builds'      => 5,
    ];

    public readonly string $root;
    public readonly string $siteDir;
    public readonly string $varDir;
    public readonly string $stubsDir;
    /** @var array<string, mixed> */
    public readonly array $config;

    public function __construct(string $root, ?string $siteDir = null)
    {
        $this->root = rtrim($root, '/');
        $this->siteDir = rtrim($siteDir ?? $this->root . '/site', '/');
        $this->varDir = $this->root . '/var';
        $this->stubsDir = dirname(__DIR__) . '/stubs';

        $file = $this->siteDir . '/config.php';
        $site = is_file($file) ? require $file : [];
        if (!is_array($site)) {
            throw new LouException("site/config.php must return an array");
        }
        $config = array_replace(self::DEFAULTS, $site, $this->settings());
        if (!in_array($config['default_language'], $config['languages'], true)) {
            throw new LouException("default_language '{$config['default_language']}' is not in languages");
        }
        $this->config = $config;
    }

    /** Install settings changed in the admin (var/settings.json). They override site/config.php. */
    public const SETTINGS = ['base_url'];

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $file = $this->varDir . '/settings.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? array_intersect_key($data, array_flip(self::SETTINGS)) : [];
    }

    /** True when base_url is set to something other than the example placeholder (example.com/.org/.net). */
    public function baseUrlConfigured(): bool
    {
        $host = strtolower((string) parse_url((string) $this->config['base_url'], PHP_URL_HOST));
        return $host !== '' && !preg_match('/(^|\.)example\.(com|org|net)$/', $host);
    }

    /**
     * Absolute site address for links Lou hands out (previews, share links, bridge URLs): base_url when it is
     * configured, otherwise the address of the current request, so a forgotten placeholder never sends people
     * to example.com. Built pages (sitemap, hreflang) still use base_url only.
     */
    public function siteUrl(?Http\Request $req = null): string
    {
        if ($this->baseUrlConfigured()) {
            return rtrim((string) $this->config['base_url'], '/');
        }
        return $req?->origin() ?: rtrim((string) $this->config['base_url'], '/');
    }

    public function outputDir(): string
    {
        $dir = (string) $this->config['output_dir'];
        return str_starts_with($dir, '/') ? rtrim($dir, '/') : $this->root . '/' . trim($dir, '/');
    }

    /** Path to the secrets file (outside the web root, never committed). */
    public function envFile(): string
    {
        return $this->root . '/.env.php';
    }

    public function databaseFile(): string
    {
        return $this->varDir . '/lou.sqlite';
    }
}
