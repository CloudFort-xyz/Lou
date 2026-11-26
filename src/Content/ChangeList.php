<?php

declare(strict_types=1);

namespace Lou\Content;

use Lou\Bridge\PathPolicy;
use Lou\Support\Diff;

/**
 * Describes a workspace's changed files in words a client understands, for the Review screen:
 * "About us (English): Title “About us” → “Who we are”" instead of a JSON line diff.
 *
 * Every changed file becomes one item with a group (pages, site-wide text, menu and settings, design, images),
 * a label, and for JSON content the fields that changed: added and removed values (tags stripped, shortened),
 * and for a changed text a word diff with only a few words of context around each change, so a one-letter fix
 * in a long paragraph is easy to spot. Templates,
 * stylesheets and scripts get a count of added and removed lines. The raw diffs stay available on the
 * Review screen under "Technical details".
 *
 * Texts are English; pass $tr (the admin's I18n::plain, fn (string $text, array $vars): string) to translate them.
 * The English texts are the translation keys, with {placeholders} for the parts that vary.
 */
final class ChangeList
{
    public const GROUPS = [
        'pages'  => 'Pages',
        'text'   => 'Site-wide text',
        'menu'   => 'Menu and settings',
        'design' => 'Design',
        'images' => 'Images and files',
        'other'  => 'Other files',
    ];

    private const FIELDS_SHOWN = 8;
    private const VALUE_LENGTH = 140;

    private const LANGUAGES = [
        'en' => 'English', 'hu' => 'Hungarian', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian',
        'nl' => 'Dutch', 'pt' => 'Portuguese', 'pl' => 'Polish', 'cs' => 'Czech', 'sk' => 'Slovak', 'ro' => 'Romanian',
        'hr' => 'Croatian', 'sr' => 'Serbian', 'sl' => 'Slovenian', 'uk' => 'Ukrainian', 'ru' => 'Russian',
    ];

    /** Friendlier names for a page's top-level keys. */
    private const PAGE_KEYS = [
        'slug' => 'Web address', 'title' => 'Title', 'menu_label' => 'Menu label', 'description' => 'Search engine description',
        'template' => 'Layout', 'parent' => 'Parent page', 'hidden' => 'Hidden',
    ];

    /**
     * @param array<string, string> $diffs path => unified diff, when already computed (Workspace::diffs())
     * @return list<array{path: string, kind: string, group: string, label: string, fields: list<array{name: string, old: ?string, new: ?string, diff: ?list<array{0: string, 1: string}>}>,
     *                    more: int, rest: list<array>, lines: ?array{0: int, 1: int}, page: ?array{id: string, lang: string}}>
     * `fields` are the first FIELDS_SHOWN changed fields, `rest` the others (`more` counts them). `lines` (added, removed)
     * is set for code and other text files whose unified diff is known, so the Review screen can unfold that diff.
     */
    public static function of(Workspace $w, ?array $diffs = null, ?\Closure $tr = null): array
    {
        $c = $w->changes();
        $items = [];
        foreach ($w->changedFiles() as $path) {
            $kind = in_array($path, $c['added'], true) ? 'added' : (in_array($path, $c['deleted'], true) ? 'deleted' : 'changed');
            [$old, $new] = PathPolicy::isBinary($path) ? [null, null] : $w->versions($path);
            $items[] = self::item($path, $kind, $old, $new, $diffs[$path] ?? null, $tr);
        }
        usort($items, fn ($a, $b) => [array_search($a['group'], array_keys(self::GROUPS), true), $a['label']]
            <=> [array_search($b['group'], array_keys(self::GROUPS), true), $b['label']]);
        return $items;
    }

    /** @return array<string, list<array>> items by group, in GROUPS order */
    public static function grouped(array $items): array
    {
        $out = [];
        foreach (array_keys(self::GROUPS) as $group) {
            $in = array_values(array_filter($items, fn ($i) => $i['group'] === $group));
            if ($in) {
                $out[$group] = $in;
            }
        }
        return $out;
    }

    /** One file: its group, label and changed fields. $old / $new: contents, null when the file does not exist on that side. */
    public static function item(string $path, string $kind, ?string $old, ?string $new, ?string $diff = null, ?\Closure $tr = null): array
    {
        $item = ['path' => $path, 'kind' => $kind, 'group' => self::group($path), 'label' => self::label($path, $new ?? $old, $tr),
            'fields' => [], 'more' => 0, 'rest' => [], 'lines' => null,
            'page' => preg_match('#^content/pages/([^/]+)/([a-z]{2})\.json$#', $path, $m) ? ['id' => $m[1], 'lang' => $m[2]] : null];
        if (PathPolicy::isBinary($path)) {
            return $item;
        }
        if (str_ends_with($path, '.json') && $kind === 'changed') {
            $a = json_decode((string) $old, true);
            $b = json_decode((string) $new, true);
            if (is_array($a) && is_array($b)) {
                $fields = self::fieldChanges($a, $b, str_starts_with($path, 'content/pages/'), $tr);
                $item['fields'] = array_slice($fields, 0, self::FIELDS_SHOWN);
                $item['rest'] = array_slice($fields, self::FIELDS_SHOWN);
                $item['more'] = count($item['rest']);
                return $item;
            }
        }
        // A new or removed page / text file is described by its label alone: its raw JSON is not for the client.
        $content = str_starts_with($path, 'content/') || $path === 'menu.json';
        if ($diff !== null && ($kind === 'changed' || !$content)) {
            $add = $del = 0;
            foreach (explode("\n", $diff) as $line) {
                $add += (int) str_starts_with($line, '+');
                $del += (int) str_starts_with($line, '-');
            }
            $item['lines'] = [$add, $del];
        }
        return $item;
    }

    public static function group(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'content/pages/')                            => 'pages',
            str_starts_with($path, 'content/')                                  => 'text',
            in_array($path, ['menu.json', 'config.php', 'redirects.json'], true) => 'menu',
            str_starts_with($path, 'templates/'), (bool) preg_match('#^assets/(css|js|fonts?)/#', $path) => 'design',
            str_starts_with($path, 'assets/')                                   => 'images',
            default                                                             => 'other',
        };
    }

    /** A name for a file that a client recognises. $contents (optional) gives a page's title. */
    public static function label(string $path, ?string $contents = null, ?\Closure $tr = null): string
    {
        $file = basename($path);
        if (preg_match('#^content/pages/([^/]+)/([a-z]{2})\.json$#', $path, $m)) {
            $data = $contents !== null ? json_decode($contents, true) : null;
            $title = is_array($data) && is_string($data['title'] ?? null) && trim($data['title']) !== '' ? trim($data['title']) : $m[1];
            return self::say($tr, '{title} ({language})', ['title' => $title, 'language' => self::language($m[2], $tr)]);
        }
        if (preg_match('#^content/pages/([^/]+)/#', $path, $m)) {
            return self::say($tr, 'Page {page}: {file}', ['page' => $m[1], 'file' => $file]);
        }
        if (preg_match('#^content/site/([a-z]{2})\.json$#', $path, $m)) {
            return self::say($tr, 'Company details and footer ({language})', ['language' => self::language($m[1], $tr)]);
        }
        if (preg_match('#^content/strings/([a-z]{2})\.json$#', $path, $m)) {
            return self::say($tr, 'Buttons, labels and messages ({language})', ['language' => self::language($m[1], $tr)]);
        }
        return match (true) {
            $path === 'menu.json'      => self::say($tr, 'Menu'),
            $path === 'config.php'     => self::say($tr, 'Site settings'),
            $path === 'redirects.json' => self::say($tr, 'Redirects'),
            $path === 'templates/layout.php'           => self::say($tr, 'Template: layout (every page)'),
            str_starts_with($path, 'templates/partials/') => self::say($tr, 'Template part: {name}', ['name' => preg_replace('/\.php$/', '', substr($path, 19))]),
            str_starts_with($path, 'templates/')          => self::say($tr, 'Template: {name}', ['name' => preg_replace('/\.php$/', '', substr($path, 10))]),
            str_starts_with($path, 'assets/css/')         => self::say($tr, 'Stylesheet: {file}', ['file' => $file]),
            str_starts_with($path, 'assets/js/')          => self::say($tr, 'Script: {file}', ['file' => $file]),
            (bool) preg_match('#^assets/fonts?/#', $path) => self::say($tr, 'Font: {file}', ['file' => $file]),
            (bool) preg_match('/\.(png|jpe?g|gif|webp|avif|svg|ico)$/i', $path) => self::say($tr, 'Image: {file}', ['file' => $file]),
            default                                       => $path,
        };
    }

    public static function language(string $code, ?\Closure $tr = null): string
    {
        return isset(self::LANGUAGES[$code]) ? self::say($tr, self::LANGUAGES[$code]) : strtoupper($code);
    }

    /** A text through the translator, or in English with its {placeholders} filled in. */
    private static function say(?\Closure $tr, string $text, array $vars = []): string
    {
        if ($tr !== null) {
            return (string) $tr($text, $vars);
        }
        return strtr($text, array_combine(array_map(fn ($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []);
    }

    /** @return list<array{name: string, old: ?string, new: ?string}> */
    private static function fieldChanges(array $a, array $b, bool $page, ?\Closure $tr = null): array
    {
        $fa = self::flatten($a);
        $fb = self::flatten($b);
        $out = [];
        foreach (array_unique(array_merge(array_keys($fa), array_keys($fb))) as $key) {
            $old = $fa[$key] ?? null;
            $new = $fb[$key] ?? null;
            if ($old === $new) {
                continue;
            }
            $a = self::text($old, $tr);
            $b = self::text($new, $tr);
            if ($a === $b) {
                continue; // only markup or whitespace changed
            }
            $diff = $a !== null && $b !== null && is_string($old) && is_string($new) ? Diff::words($a, $b) : null;
            // a word diff helps when part of the text stayed; a full replacement reads better as old → new
            $diff = $diff && in_array(' ', array_column($diff, 0), true) ? self::context($diff) : null;
            $out[] = ['name' => self::fieldName((string) $key, $page, $tr), 'old' => self::shorten($a), 'new' => self::shorten($b), 'diff' => $diff];
        }
        return $out;
    }

    /** @return array<string, mixed> "fields.hero.title" => value; empty arrays are kept as values */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : "$prefix.$k";
            if (is_array($v) && $v !== []) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    private static function fieldName(string $key, bool $page, ?\Closure $tr = null): string
    {
        if ($page && isset(self::PAGE_KEYS[$key])) {
            return self::say($tr, self::PAGE_KEYS[$key]);
        }
        $parts = explode('.', $key);
        if ($page && $parts[0] === 'fields' && count($parts) > 1) {
            array_shift($parts);
        }
        $parts = array_map(fn ($p) => ctype_digit($p) ? self::say($tr, 'item {n}', ['n' => (int) $p + 1]) : str_replace(['_', '-'], ' ', $p), $parts);
        return ucfirst(implode(' › ', $parts));
    }

    /** A value as plain text: tags stripped (block ends become spaces), entities decoded, whitespace collapsed. */
    private static function text(mixed $v, ?\Closure $tr = null): ?string
    {
        return match (true) {
            $v === null => null,
            is_bool($v) => self::say($tr, $v ? 'yes' : 'no'),
            $v === []   => self::say($tr, '(empty)'),
            default     => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
                (string) preg_replace('#<(br|/p|/li|/h[1-6]|/div)\b[^>]*>#i', '$0 ', (string) $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
        };
    }

    private static function shorten(?string $s): ?string
    {
        return $s !== null && mb_strlen($s) > self::VALUE_LENGTH ? mb_substr($s, 0, self::VALUE_LENGTH - 1) . '…' : $s;
    }

    /**
     * Keep a few words of unchanged text around each change: the start and end of a long text are cut to
     * "…" plus the nearest words, and long unchanged stretches in the middle to "first words … last words".
     * @param list<array{0: string, 1: string}> $runs
     * @return list<array{0: string, 1: string}>
     */
    private static function context(array $runs, int $words = 6): array
    {
        $last = count($runs) - 1;
        foreach ($runs as $i => [$op, $text]) {
            if ($op !== ' ') {
                continue;
            }
            $w = preg_split('/(?<=\s)(?=\S)/u', $text) ?: [$text];
            if (count($w) <= $words * 2) {
                continue;
            }
            $head = implode('', array_slice($w, 0, $words));
            $tail = implode('', array_slice($w, -$words));
            $runs[$i][1] = match (true) {
                $i === 0 && $i === $last => $head . '…',
                $i === 0                 => '…' . $tail,
                $i === $last             => $head . '…',
                default                  => rtrim($head) . ' … ' . $tail,
            };
        }
        return $runs;
    }
}
