<?php

declare(strict_types=1);

namespace Lou\Content;

use Lou\App;
use Lou\LouException;
use Lou\Support\Fs;
use Lou\Support\Html;

/**
 * The only way the admin editor writes content. It can change values inside a page's `fields`
 * and its title/description/menu label (plus the slug for admins). Never templates or structure.
 */
final class ContentStore
{
    private const SEGMENT = '/^[A-Za-z0-9_]+$/';
    private const MAX_DEPTH = 5;

    public function __construct(private readonly App $app)
    {
    }

    public function file(string $id, string $lang): string
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) || !in_array($lang, $this->app->config['languages'], true)) {
            throw new LouException('Invalid page or language');
        }
        $file = $this->app->siteDir . "/content/pages/$id/$lang.json";
        if (!is_file($file)) {
            throw new LouException("Page '$id' does not exist in '$lang'");
        }
        return $file;
    }

    public function read(string $id, string $lang): array
    {
        return Fs::readJson($this->file($id, $lang));
    }

    /**
     * Set a field. $type: text (plain, tags stripped), html (sanitised rich text).
     * The path must point into an existing structure; an existing array (e.g. an image) can't be overwritten with text.
     */
    public function setField(string $id, string $lang, string $path, string $type, string $value): void
    {
        $value = match ($type) {
            'text'  => trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? ''),
            'html'  => Html::sanitise($value),
            default => throw new LouException("Unknown field type '$type'"),
        };
        if (mb_strlen($value) > 50000) {
            throw new LouException('Value too long');
        }
        $this->update($id, $lang, function (array $data) use ($path, $value): array {
            $data['fields'] = self::put($data['fields'] ?? [], $path, $value, false);
            return $data;
        });
    }

    /** @param array{src: string, alt?: string, width?: int, height?: int} $image */
    public function setImage(string $id, string $lang, string $path, array $image): void
    {
        if (!str_starts_with($image['src'], '/assets/')) {
            throw new LouException('Images must live under /assets/');
        }
        $this->update($id, $lang, function (array $data) use ($path, $image): array {
            $current = self::get($data['fields'] ?? [], $path);
            $merged = (is_array($current) ? $current : []) + [];
            foreach (['src', 'alt', 'width', 'height'] as $k) {
                if (array_key_exists($k, $image)) {
                    $merged[$k] = $k === 'width' || $k === 'height' ? (int) $image[$k] : trim(strip_tags((string) $image[$k]));
                }
            }
            $data['fields'] = self::put($data['fields'] ?? [], $path, $merged, true);
            return $data;
        });
    }

    /** @param array<string, string> $meta title, description, menu_label, slug */
    public function setMeta(string $id, string $lang, array $meta, bool $allowSlug): void
    {
        $this->update($id, $lang, function (array $data) use ($meta, $allowSlug, $id): array {
            foreach (['title' => 200, 'description' => 500, 'menu_label' => 80] as $k => $max) {
                if (array_key_exists($k, $meta)) {
                    $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $meta[$k])) ?? '');
                    if (mb_strlen($v) > $max) {
                        throw new LouException("$k is too long (max $max)");
                    }
                    if ($v === '' && $k !== 'title') {
                        unset($data[$k]);
                    } else {
                        $data[$k] = $v;
                    }
                }
            }
            if ($allowSlug && isset($meta['slug']) && !in_array($id, [Page::HOME, Page::NOT_FOUND], true)) {
                $slug = (string) $meta['slug'];
                if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                    throw new LouException('Slugs may contain lowercase letters, digits and dashes');
                }
                $data['slug'] = $slug;
            }
            return $data;
        });
    }

    private function update(string $id, string $lang, callable $fn): void
    {
        $file = $this->file($id, $lang);
        $original = (string) file_get_contents($file);
        $data = $fn(Fs::readJson($file));
        $this->replace($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        // the whole site must still load with the change (e.g. no duplicate slugs); otherwise undo it
        try {
            Site::load($this->app);
        } catch (LouException $e) {
            $this->replace($file, $original);
            throw $e;
        }
    }

    private function replace(string $file, string $contents): void
    {
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
        Fs::write($tmp, $contents);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new LouException("Cannot write $file");
        }
    }

    private static function segments(string $path): array
    {
        $parts = explode('.', $path);
        if (count($parts) > self::MAX_DEPTH) {
            throw new LouException('Field path too deep');
        }
        foreach ($parts as $p) {
            if (!preg_match(self::SEGMENT, $p)) {
                throw new LouException("Invalid field path '$path'");
            }
        }
        return $parts;
    }

    private static function get(array $fields, string $path): mixed
    {
        $cur = $fields;
        foreach (self::segments($path) as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return null;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }

    private static function put(array $fields, string $path, mixed $value, bool $allowArray): array
    {
        $parts = self::segments($path);
        $ref = &$fields;
        foreach ($parts as $i => $p) {
            $last = $i === count($parts) - 1;
            if ($last) {
                if (isset($ref[$p]) && is_array($ref[$p]) && !$allowArray) {
                    throw new LouException("Field '$path' is not a text field");
                }
                $ref[$p] = $value;
            } else {
                if (isset($ref[$p]) && !is_array($ref[$p])) {
                    throw new LouException("Field '$path' does not match the content structure");
                }
                $ref[$p] ??= [];
                $ref = &$ref[$p];
            }
        }
        return $fields;
    }
}
