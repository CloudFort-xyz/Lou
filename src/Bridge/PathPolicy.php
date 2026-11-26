<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\LouException;

/** Which files under site/ the LLM bridge may read and write. */
final class PathPolicy
{
    private const TEXT_EXT = ['php', 'json', 'css', 'js', 'svg', 'txt', 'md', 'html'];
    private const BINARY_EXT = ['webp', 'jpg', 'jpeg', 'png', 'gif', 'ico', 'woff2', 'woff'];
    public const MAX_TEXT = 512 * 1024;

    /** Normalise and validate a path relative to site/. */
    public static function normalise(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'site/')) {
            $path = substr($path, 5);
        }
        if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $path) || preg_match('#(^|/)\.#', $path)) {
            throw new LouException("Invalid path '$path' (relative to site/, no dotfiles or '..')");
        }
        if (!preg_match('#^[A-Za-z0-9_\-./]+$#', $path)) {
            throw new LouException("Invalid characters in path '$path' (use ASCII letters, digits, - _ . /)");
        }
        return $path;
    }

    public static function checkWrite(string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'php' && !str_starts_with($path, 'templates/') && $path !== 'config.php') {
            throw new LouException('PHP files may only live in templates/ (or be site/config.php)');
        }
        if (!in_array($ext, self::TEXT_EXT, true)) {
            throw new LouException("Text files must be one of: " . implode(', ', self::TEXT_EXT) . ' (use upload_site_image for images)');
        }
        $top = explode('/', $path)[0];
        if (!in_array($top, ['templates', 'content', 'assets', 'config.php', 'menu.json', 'redirects.json'], true)) {
            throw new LouException("Files must be under templates/, content/ or assets/, or be config.php, menu.json, redirects.json");
        }
    }

    public static function isBinary(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::BINARY_EXT, true);
    }

    /** @return list<string> problems with the content (empty = OK) */
    public static function checkContent(string $path, string $content): array
    {
        if (strlen($content) > self::MAX_TEXT) {
            return ["$path is too large (max 512 KB)"];
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            return ["$path is not valid UTF-8"];
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'php'  => array_merge(
                TemplateGuard::check($content, $path),
                $path === 'config.php' && !preg_match('/^\s*<\?php\s.*return\s*\[/s', $content) ? ['config.php must return an array'] : [],
            ),
            'json' => json_decode($content) === null && trim($content) !== 'null' ? ["$path: invalid JSON (" . json_last_error_msg() . ')'] : [],
            'svg'  => preg_match('/<script|\son[a-z]+\s*=|javascript:|<foreignObject|<!ENTITY/i', $content) ? ["$path: SVGs may not contain scripts, event handlers or entities"] : [],
            'html' => preg_match('/<\?/', $content) ? ["$path: no PHP in .html files"] : [],
            default => [],
        };
    }
}
