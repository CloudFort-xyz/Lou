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
        $config = array_replace(self::DEFAULTS, $site);
        if (!in_array($config['default_language'], $config['languages'], true)) {
            throw new LouException("default_language '{$config['default_language']}' is not in languages");
        }
        $this->config = $config;
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
