<?php

declare(strict_types=1);

namespace Lou\Content;

use Lou\App;
use Lou\LouException;
use Lou\Support\Fs;
use Lou\Support\Html;

/**
 * The only way the admin editor writes content. It can change values inside a page's `fields`
 * and its title/description/menu label (plus the slug for admins). Never templates or structure,
 * except duplicating or deleting pages whose template the site lists in admin.repeatable (e.g. job ads).
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

    public const ITEM_ACTIONS = ['duplicate', 'delete', 'up', 'down'];
    public const MAX_ITEMS = 100;

    /**
     * Duplicate, delete or move one item of a list field (e.g. "features.2" in "features": [...]).
     * Only lists that already exist in the content can change, so the template decides what repeats.
     *
     * The same change is made in every language whose list has the same length, so item N stays the
     * same item in every language. Each language duplicates its own text. Languages whose list differs
     * are left alone and returned, so the editor can say so.
     *
     * @return array{index: int, skipped: list<string>} index: where the item is now (the copy, for duplicate)
     */
    public function itemAction(string $id, string $lang, string $path, string $action): array
    {
        if (!in_array($action, self::ITEM_ACTIONS, true)) {
            throw new LouException("Unknown item action '$action'");
        }
        $parts = self::segments($path);
        $index = array_pop($parts);
        if ($parts === [] || !ctype_digit($index)) {
            throw new LouException("'$path' is not a list item");
        }
        $listPath = implode('.', $parts);
        $i = (int) $index;
        $list = self::get($this->read($id, $lang)['fields'] ?? [], $listPath);
        if (!is_array($list) || !array_is_list($list) || !array_key_exists($i, $list)) {
            throw new LouException("'$path' is not a list item");
        }
        $count = count($list);
        $to = match ($action) {
            'duplicate' => $count >= self::MAX_ITEMS ? throw new LouException('This list is full (' . self::MAX_ITEMS . ' items)') : $i + 1,
            'delete'    => $count <= 1 ? throw new LouException('The last item of a list can\'t be deleted. Ask your assistant to remove the whole section.') : min($i, $count - 2),
            'up'        => $i === 0 ? throw new LouException('This is already the first item') : $i - 1,
            'down'      => $i === $count - 1 ? throw new LouException('This is already the last item') : $i + 1,
        };

        $originals = [];
        $skipped = [];
        foreach ($this->app->config['languages'] as $l) {
            $file = $this->app->siteDir . "/content/pages/$id/$l.json";
            if (!is_file($file)) {
                continue;
            }
            $data = Fs::readJson($file);
            $items = self::get($data['fields'] ?? [], $listPath);
            if (!is_array($items) || !array_is_list($items) || count($items) !== $count) {
                $skipped[] = $l;
                continue;
            }
            switch ($action) {
                case 'duplicate':
                    array_splice($items, $i + 1, 0, [$items[$i]]);
                    break;
                case 'delete':
                    array_splice($items, $i, 1);
                    break;
                default:
                    [$items[$i], $items[$to]] = [$items[$to], $items[$i]];
            }
            $data['fields'] = self::put($data['fields'] ?? [], $listPath, $items, true);
            $originals[$file] = (string) file_get_contents($file);
            $this->replace($file, self::encode($data));
        }
        try {
            Site::load($this->app);
        } catch (LouException $e) {
            foreach ($originals as $file => $contents) {
                $this->replace($file, $contents);
            }
            throw $e;
        }
        return ['index' => $to, 'skipped' => $skipped];
    }

    /** Templates whose pages editors may duplicate and delete (site config admin.repeatable). */
    public function isRepeatable(string $id): bool
    {
        $templates = (array) ($this->app->config['admin']['repeatable'] ?? []);
        if (in_array($id, [Page::HOME, Page::NOT_FOUND], true) || $templates === []) {
            return false;
        }
        foreach ($this->app->config['languages'] as $lang) {
            $file = $this->app->siteDir . "/content/pages/$id/$lang.json";
            if (is_file($file)) {
                return in_array((string) (Fs::readJson($file)['template'] ?? 'page'), $templates, true);
            }
        }
        return false;
    }

    /**
     * Copies a repeatable page (every language) to a new id with "-2", "-3"… appended to id, slugs and labels.
     * The copy sits next to the original (same parent and template) and is edited like any page.
     */
    public function duplicatePage(string $id): string
    {
        $this->file($id, $this->app->config['default_language']);
        if (!$this->isRepeatable($id)) {
            throw new LouException("Page '$id' can't be duplicated in the editor");
        }
        $pages = $this->app->siteDir . '/content/pages';
        $base = preg_replace('/-\d+$/', '', $id) ?? $id;
        for ($n = 2; is_dir("$pages/$base-$n"); $n++) {
        }
        $new = "$base-$n";
        foreach ($this->app->config['languages'] as $lang) {
            $data = $this->read($id, $lang);
            $data['slug'] = (preg_replace('/-\d+$/', '', (string) ($data['slug'] ?? $base)) ?? $base) . "-$n";
            $data['title'] = trim((string) ($data['title'] ?? '')) . " ($n)";
            if (isset($data['menu_label'])) {
                $data['menu_label'] = trim((string) $data['menu_label']) . " ($n)";
            }
            $data['order'] = (int) ($data['order'] ?? 0) + 1;
            Fs::writeJson("$pages/$new/$lang.json", $data);
        }
        try {
            Site::load($this->app);
        } catch (LouException $e) {
            Fs::removeDir("$pages/$new");
            throw $e;
        }
        return $new;
    }

    /** Deletes a repeatable page in every language. Pages in the menu or with child pages can't be deleted here. */
    public function deletePage(string $id): void
    {
        $this->file($id, $this->app->config['default_language']);
        if (!$this->isRepeatable($id)) {
            throw new LouException("Page '$id' can't be deleted in the editor");
        }
        $site = Site::load($this->app);
        foreach ($site->allPages() as $p) {
            if ($p->parent === $id) {
                throw new LouException("Page '$id' has child pages, delete those first");
            }
        }
        $inMenu = false;
        $menu = $site->menu();
        array_walk_recursive($menu, function ($v, $k) use ($id, &$inMenu): void {
            $inMenu = $inMenu || ($k === 'page' && $v === $id);
        });
        if ($inMenu) {
            throw new LouException("Page '$id' is in the menu, so it can't be deleted here");
        }
        Fs::removeDir($this->app->siteDir . "/content/pages/$id");
    }

    private function update(string $id, string $lang, callable $fn): void
    {
        $file = $this->file($id, $lang);
        $original = (string) file_get_contents($file);
        $data = $fn(Fs::readJson($file));
        $this->replace($file, self::encode($data));
        // the whole site must still load with the change (e.g. no duplicate slugs); otherwise undo it
        try {
            Site::load($this->app);
        } catch (LouException $e) {
            $this->replace($file, $original);
            throw $e;
        }
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
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
