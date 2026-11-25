<?php

declare(strict_types=1);

namespace Lou\Build;

use Lou\Content\Page;
use Lou\Content\Site;
use Lou\LouException;
use Lou\Support\Html;

/**
 * Maintains site/redirects.json by diffing URL manifests between builds,
 * and turns the entries into .htaccess rules and static fallback files.
 *
 * Entry shapes:
 *   {"from": "/rolunk/", "to": {"page": "about", "lang": "hu"}, "auto": "moved"}
 *   {"from": "/old/",    "to": "https://elsewhere.example/"}
 *   {"from": "/gone/",   "status": 410}
 */
final class Redirects
{
    /**
     * Update entries given the previous and current manifests ("id|lang" => url).
     *
     * @param array<string, string> $prev
     * @param array<string, string> $curr
     * @param list<array<string, mixed>> $entries
     * @return array{0: list<array<string, mixed>>, 1: array{moved: list<array>, gone: list<array>}}
     */
    public static function update(array $prev, array $curr, array $entries, string $defaultLang): array
    {
        $report = ['moved' => [], 'gone' => []];
        $byFrom = [];
        foreach ($entries as $e) {
            $byFrom[$e['from']] = $e;
        }

        foreach ($prev as $key => $oldUrl) {
            [$id, $lang] = explode('|', $key, 2);
            if (isset($curr[$key])) {
                if ($curr[$key] !== $oldUrl) {
                    $byFrom[$oldUrl] = ['from' => $oldUrl, 'to' => ['page' => $id, 'lang' => $lang], 'auto' => 'moved'];
                    $report['moved'][] = ['from' => $oldUrl, 'to' => $curr[$key], 'page' => $id, 'lang' => $lang];
                }
            } elseif (!isset($byFrom[$oldUrl])) {
                $home = isset($curr[Page::HOME . "|$lang"]) ? $lang : $defaultLang;
                $byFrom[$oldUrl] = ['from' => $oldUrl, 'to' => ['page' => Page::HOME, 'lang' => $home], 'auto' => 'gone'];
                $report['gone'][] = ['from' => $oldUrl, 'page' => $id, 'lang' => $lang];
            }
        }

        // entries pointing at a page that no longer exists
        foreach ($byFrom as $from => $e) {
            if (is_array($e['to'] ?? null) && !isset($curr[$e['to']['page'] . '|' . $e['to']['lang']])) {
                $lang = $e['to']['lang'];
                $byFrom[$from]['to'] = ['page' => Page::HOME, 'lang' => isset($curr[Page::HOME . "|$lang"]) ? $lang : $defaultLang];
                $byFrom[$from]['auto'] = 'gone';
                $report['gone'][] = ['from' => $from, 'page' => $e['to']['page'], 'lang' => $lang];
            }
        }

        // a URL that is live again (e.g. a rename was undone) no longer needs an automatic redirect
        $live = array_flip($curr);
        foreach ($byFrom as $from => $e) {
            if (isset($live[$from]) && isset($e['auto'])) {
                unset($byFrom[$from]);
            }
        }

        ksort($byFrom);
        return [array_values($byFrom), $report];
    }

    /**
     * Resolve entries to concrete rules and validate them.
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, string> $curr
     * @return list<array{from: string, status: int, to: ?string}>
     */
    public static function resolve(Site $site, array $entries, array $curr): array
    {
        $live = array_flip($curr);
        $errors = [];
        $rules = [];
        foreach ($entries as $e) {
            $from = (string) ($e['from'] ?? '');
            if ($from === '' || $from[0] !== '/') {
                $errors[] = "Redirect 'from' must start with '/': '$from'";
                continue;
            }
            if (!self::safeTarget($from) || preg_match('#(^|/)\.\.?(/|$)#', $from)) {
                // a newline would end the RedirectMatch line in .htaccess, and ".." would let the fallback page escape the build
                $errors[] = 'Redirect from ' . json_encode($from, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . ': no spaces, quotes, backslashes, control characters or ./.. segments';
                continue;
            }
            if (isset($live[$from])) {
                $errors[] = "Redirect from $from shadows a live page; remove it from site/redirects.json";
                continue;
            }
            if ((int) ($e['status'] ?? 301) === 410) {
                $rules[] = ['from' => $from, 'status' => 410, 'to' => null];
                continue;
            }
            $to = $e['to'] ?? null;
            if (is_array($to)) {
                try {
                    $to = $site->path((string) $to['page'], (string) $to['lang']);
                } catch (LouException $ex) {
                    $errors[] = "Redirect from $from: " . $ex->getMessage();
                    continue;
                }
            }
            if (!is_string($to) || $to === '') {
                $errors[] = "Redirect from $from has no target";
                continue;
            }
            if (!self::safeTarget($to) || !preg_match('#^(/|https?://)#', $to)) {
                $errors[] = "Redirect from $from: the target must be a path starting with '/' or an http(s) URL, "
                    . 'with no spaces, quotes, backslashes or control characters';
                continue;
            }
            if (rtrim($to, '/') === rtrim($from, '/')) {
                $errors[] = "Redirect loop: $from → $to";
                continue;
            }
            $rules[] = ['from' => $from, 'status' => (int) ($e['status'] ?? 301), 'to' => $to];
        }
        if ($errors) {
            throw new LouException('Invalid redirects:', $errors);
        }
        return $rules;
    }

    /** No whitespace, control characters, quotes or backslashes: they would break out of the quoted .htaccess value. */
    public static function safeTarget(string $s): bool
    {
        return !preg_match('/[\x00-\x20\x7f"\\\\]/', $s) && mb_check_encoding($s, 'UTF-8');
    }

    /** Apache mod_alias rules (exact match, optional trailing slash). */
    public static function htaccess(array $rules): string
    {
        $out = [];
        foreach ($rules as $r) {
            $pattern = '^' . preg_quote(rtrim($r['from'], '/'), '#') . '/?$';
            $out[] = $r['status'] === 410
                ? sprintf('RedirectMatch 410 "%s"', $pattern)
                : sprintf('RedirectMatch %d "%s" "%s"', $r['status'], $pattern, str_replace('"', '%22', $r['to']));
        }
        return implode("\n", $out);
    }

    /** Static fallback for servers that ignore .htaccess. */
    public static function fallbackHtml(string $to): string
    {
        $u = Html::escape($to);
        return "<!doctype html>\n<meta charset=\"utf-8\">\n<meta name=\"robots\" content=\"noindex\">\n"
            . "<link rel=\"canonical\" href=\"$u\">\n<meta http-equiv=\"refresh\" content=\"0; url=$u\">\n"
            . "<a href=\"$u\">$u</a>\n";
    }
}
