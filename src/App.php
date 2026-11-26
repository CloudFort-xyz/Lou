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
        'output_swap'      => 'rename',    // 'contents' for a document root Lou can't rename (see Build\Output)
        'keep_builds'      => 5,
        'endpoint_dir'     => '_lou',      // web folder of the admin, form and MCP endpoints (installs since 0.2 use a neutral name)
    ];

    /** Folder name new installs get for the endpoints, so the public site does not say which CMS it runs. */
    public const NEUTRAL_ENDPOINT_DIR = '_app';

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
        unset($site['endpoint_dir']);   // a server setting only: site content (or an assistant's draft) can't move the admin
        $settings = $this->settings();
        $config = array_replace(self::DEFAULTS, $site, $settings);
        if (isset($settings['mail'])) {
            // the admin only sets some mail keys; keep the rest from site/config.php
            $config['mail'] = array_replace((array) ($site['mail'] ?? []), (array) $settings['mail']);
        }
        if (!self::validEndpointDir((string) $config['endpoint_dir'])) {
            throw new LouException("endpoint_dir '{$config['endpoint_dir']}' must be 2-40 lowercase letters, digits, _ or -, and not start with . or -");
        }
        if (!in_array($config['default_language'], $config['languages'], true)) {
            throw new LouException("default_language '{$config['default_language']}' is not in languages");
        }
        $this->config = $config;
    }

    /**
     * Install settings (var/settings.json), changed in the admin or written by the installer.
     * They belong to this server, not to the site, so they override site/config.php.
     */
    public const SETTINGS = ['base_url', 'mail', 'output_dir', 'output_swap', 'admin_language', 'endpoint_dir'];

    /** The mail keys the admin may set (mail.transport, mail.from, mail.from_name). */
    public const MAIL_SETTINGS = ['transport', 'from', 'from_name'];

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $file = $this->varDir . '/settings.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = is_array($data) ? array_intersect_key($data, array_flip(self::SETTINGS)) : [];
        if (isset($data['mail'])) {
            $data['mail'] = array_intersect_key((array) $data['mail'], array_flip(self::MAIL_SETTINGS));
        }
        return $data;
    }

    /**
     * Checks and normalises a site address typed by a person: https://www.example.hu, no path.
     * @throws LouException
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $p = parse_url($url);
        if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])
            || isset($p['user']) || isset($p['query']) || isset($p['fragment']) || ($p['path'] ?? '') !== '') {
            throw new LouException('The site address must look like https://www.example.hu (no path)');
        }
        return strtolower($p['scheme'] . '://' . $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
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

    /** A folder name for the endpoints: one path segment, no dot files, nothing that clashes with assets. */
    public static function validEndpointDir(string $dir): bool
    {
        return (bool) preg_match('/^[a-z0-9_][a-z0-9_-]{1,39}$/', $dir) && $dir !== 'assets';
    }

    /**
     * Web path of an endpoint file or folder, e.g. endpoint('admin.php') = /_app/admin.php, endpoint() = /_app.
     * The folder is the endpoint_dir setting (see NEUTRAL_ENDPOINT_DIR).
     */
    public function endpoint(string $file = ''): string
    {
        return '/' . $this->config['endpoint_dir'] . ($file === '' ? '' : '/' . ltrim($file, '/'));
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

    /** Secrets saved in the admin (SMTP). Outside the web root, mode 0600, never in backups. They override .env.php. */
    public function secretsFile(): string
    {
        return $this->varDir . '/secrets.json';
    }

    public function databaseFile(): string
    {
        return $this->varDir . '/lou.sqlite';
    }
}
