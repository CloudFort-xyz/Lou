<?php

declare(strict_types=1);

namespace Lou\Setup;

use Lou\App;

/**
 * Checks that this PHP and this install can run Lou: version, extensions, writable folders, config.
 * Probes are injectable so the checks can be tested without changing the PHP build.
 */
final class Requirements
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** extension => what needs it */
    private const REQUIRED = [
        'pdo_sqlite' => 'form submissions and subscribers (SQLite)',
        'gd'         => 'image uploads in the editor',
        'curl'       => 'newsletter providers, captcha checks and webhooks',
        'mbstring'   => 'text handling',
        'zip'        => 'backups and restore',
        'dom'        => 'rich text cleaning',
        'openssl'    => 'SMTP over TLS',
    ];

    private const RECOMMENDED = [
        'intl' => 'better slugs from accented text (falls back to iconv)',
        'exif' => 'auto-rotating phone photos on upload',
        'sodium' => 'checking the signature of Lou updates',
    ];

    private readonly \Closure $hasExtension;
    private readonly \Closure $hasFunction;
    private readonly int $phpVersionId;

    public function __construct(
        private readonly string $root,
        ?\Closure $hasExtension = null,
        ?\Closure $hasFunction = null,
        ?int $phpVersionId = null,
        private readonly ?string $memoryLimit = null,
    ) {
        $this->hasExtension = $hasExtension ?? static fn (string $e): bool => extension_loaded($e);
        $this->hasFunction = $hasFunction ?? static fn (string $f): bool => function_exists($f)
            && !in_array($f, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $this->phpVersionId = $phpVersionId ?? PHP_VERSION_ID;
    }

    /**
     * PHP and install checks together.
     * @return list<array{level: string, label: string, detail: string}>
     */
    public function run(): array
    {
        return [...$this->php(), ...$this->install()];
    }

    /**
     * PHP checks only: version, extensions, memory.
     * @return list<array{level: string, label: string, detail: string}>
     */
    public function php(): array
    {
        $r = [];
        $v = intdiv($this->phpVersionId, 10000) . '.' . intdiv($this->phpVersionId % 10000, 100) . '.' . $this->phpVersionId % 100;
        $r[] = $this->phpVersionId >= 80300
            ? $this->item(self::OK, "PHP $v", PHP_BINARY)
            : $this->item(self::FAIL, "PHP $v", 'Lou needs PHP 8.3 or newer. Pick ea-php83 in MultiPHP Manager and call it by full path.');

        foreach (self::REQUIRED as $ext => $why) {
            $r[] = ($this->hasExtension)($ext)
                ? $this->item(self::OK, "ext-$ext", '')
                : $this->item(self::FAIL, "ext-$ext", "missing, needed for $why");
        }
        if (($this->hasExtension)('gd')) {
            $r[] = ($this->hasFunction)('imagewebp')
                ? $this->item(self::OK, 'GD WebP support', '')
                : $this->item(self::WARN, 'GD WebP support', 'missing, uploads are saved as JPEG/PNG instead of WebP');
        }
        foreach (self::RECOMMENDED as $ext => $why) {
            $r[] = ($this->hasExtension)($ext)
                ? $this->item(self::OK, "ext-$ext", '')
                : $this->item(self::WARN, "ext-$ext", "missing (recommended): $why");
        }

        $mem = $this->bytes($this->memoryLimit ?? (string) ini_get('memory_limit'));
        if ($mem !== -1 && $mem < 128 * 1024 * 1024) {
            $r[] = $this->item(self::WARN, 'memory_limit', 'below 128M, large photo uploads may fail');
        }

        return $r;
    }

    /** True when nothing failed. */
    public static function passed(array $results): bool
    {
        foreach ($results as $i) {
            if ($i['level'] === self::FAIL) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array{level: string, label: string, detail: string}> */
    private function install(): array
    {
        $r = [];
        $root = $this->root;
        if (!is_dir($root)) {
            return [$this->item(self::WARN, 'install folder', "$root does not exist yet (php lou deploy creates it)")];
        }
        foreach (['', '/site', '/var'] as $sub) {
            $dir = $root . $sub;
            if (is_dir($dir) && !is_writable($dir)) {
                $r[] = $this->item(self::FAIL, 'writable ' . ($sub ?: 'install folder'), "$dir is not writable by " . $this->user());
            }
        }
        if (!is_dir("$root/site")) {
            return [...$r, $this->item(self::WARN, 'site/', "no site/ in $root yet")];
        }

        try {
            $app = new App($root);
        } catch (\Throwable $e) {
            return [...$r, $this->item(self::FAIL, 'site/config.php', $e->getMessage())];
        }
        $out = $app->outputDir();
        $parent = dirname($out);
        $contents = ($app->config['output_swap'] ?? 'rename') === 'contents';
        if ($contents) {
            $r[] = is_dir($out) && is_writable($out) ? $this->item(self::OK, 'output folder', "$out (Lou replaces only its own files there)")
                : $this->item(self::FAIL, 'output folder', "$out must exist and be writable (output_swap is 'contents')");
        } elseif (is_link($out)) {
            $r[] = $this->item(self::FAIL, 'output folder', "$out is a symlink. Point the symlink at it instead (public_html → $out), not the other way round, so the atomic swap works.");
        } elseif (!is_dir($parent) || !is_writable($parent)) {
            $r[] = $this->item(self::FAIL, 'output folder', "$parent must be writable, builds are swapped in by renaming next to $out");
        } else {
            $r[] = $this->item(self::OK, 'output folder', $out);
        }

        $base = (string) $app->config['base_url'];
        if ($base === '') {
            $r[] = $this->item(self::WARN, 'base_url', "not set: set the real https:// address in Admin → Settings or site/config.php (sitemap, hreflang, bridge URL)");
        } elseif (!$app->baseUrlConfigured()) {
            $r[] = $this->item(self::FAIL, 'base_url', "still the example ($base): set the real https:// address in Admin → Settings (or site/config.php, then php lou build)");
        }
        if (!is_file($app->envFile())) {
            $r[] = $this->item(self::WARN, '.env.php', 'missing: copy .env.example.php to .env.php for API keys (SMTP can be set in Admin → Settings)');
        }
        $transport = $app->config['mail']['transport'] ?? 'log';
        if ($transport === 'log') {
            $r[] = $this->item(self::WARN, 'mail', "transport is 'log', form emails only go to var/mail.log (change it in Admin → Settings)");
        } elseif ($transport === 'smtp') {
            try {
                $host = \Lou\Env::load($app)->get('smtp.host');
            } catch (\Throwable) {
                $host = null;
            }
            if (!is_string($host) || $host === '') {
                $r[] = $this->item(self::FAIL, 'mail', 'no SMTP server: set it in Admin → Settings (or smtp in .env.php), form emails cannot be sent');
            }
        }
        if (!empty($app->config['git']['commit']) && !($this->hasFunction)('proc_open')) {
            $r[] = $this->item(self::FAIL, 'git commit', "'git' => ['commit' => true] needs proc_open, which is disabled");
        }
        return $r;
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function user(): string
    {
        return function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? 'this user')
            : 'this user';
    }

    /** @return array{level: string, label: string, detail: string} */
    private function item(string $level, string $label, string $detail): array
    {
        return ['level' => $level, 'label' => $label, 'detail' => $detail];
    }
}
