<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\App;
use Lou\Content\Site;
use Lou\Content\Workspace;
use Lou\LouException;
use Lou\Support\Fs;

/**
 * Undo and redo for the inline editor, per person and per page.
 *
 * Every editor change records the page's content files (content/pages/<id>/*.json) before and after.
 * Undo writes the "before" versions back into the person's draft, redo the "after" versions.
 * It only does so while the files still look exactly like they did right after that change,
 * so it never overwrites something that changed since (a newer publish, another tab, an assistant).
 *
 * Stored in var/undo/<owner hash>.json. Only the last MAX entries are kept. This is separate from
 * History (which records publishes): it covers the edits in your draft that nobody has seen yet.
 */
final class EditHistory
{
    public const MAX = 50;

    private readonly string $file;

    public function __construct(private readonly App $app, private readonly Workspace $draft)
    {
        $this->file = $app->varDir . '/undo/' . substr(hash('sha256', $draft->owner), 0, 16) . '.json';
    }

    /** @return array<string, string> relative path → contents of the page's content files as this person sees them */
    public function snapshot(string $page): array
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $page)) {
            return [];
        }
        $out = [];
        $base = $this->draft->app()->siteDir;
        foreach (glob("$base/content/pages/$page/*.json") ?: [] as $f) {
            $out["content/pages/$page/" . basename($f)] = (string) file_get_contents($f);
        }
        return $out;
    }

    /** Record a change made by the editor. Clears that page's redo steps. */
    public function record(string $page, string $lang, string $label, array $before, array $after): void
    {
        $files = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $path) {
            $b = $before[$path] ?? null;
            $a = $after[$path] ?? null;
            if ($a !== $b) {
                $files[$path] = ['before' => $b, 'after' => $a];
            }
        }
        if ($files === []) {
            return;
        }
        $data = $this->load();
        $data['redo'] = array_values(array_filter($data['redo'], fn ($e) => $e['page'] !== $page));
        $data['undo'][] = ['page' => $page, 'lang' => $lang, 'label' => $label, 'at' => gmdate('c'), 'files' => $files];
        $data['undo'] = array_slice($data['undo'], -self::MAX);
        $this->save($data);
    }

    /** @return array{undo: ?string, redo: ?string} labels of the next undo/redo step for this page */
    public function state(string $page): array
    {
        $data = $this->load();
        $find = function (array $stack) use ($page): ?string {
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if ($stack[$i]['page'] === $page) {
                    return $stack[$i]['label'];
                }
            }
            return null;
        };
        return ['undo' => $find($data['undo']), 'redo' => $find($data['redo'])];
    }

    /** @return array{label: string, lang: string} what was undone */
    public function undo(string $page): array
    {
        return $this->step($page, 'undo', 'redo', 'after', 'before');
    }

    /** @return array{label: string, lang: string} what was redone */
    public function redo(string $page): array
    {
        return $this->step($page, 'redo', 'undo', 'before', 'after');
    }

    private function step(string $page, string $from, string $to, string $expect, string $restore): array
    {
        $data = $this->load();
        $index = null;
        for ($i = count($data[$from]) - 1; $i >= 0; $i--) {
            if ($data[$from][$i]['page'] === $page) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new LouException($from === 'undo' ? 'Nothing to undo on this page' : 'Nothing to redo on this page');
        }
        $entry = $data[$from][$index];
        array_splice($data[$from], $index, 1);

        $current = $this->draft->app()->siteDir;
        foreach ($entry['files'] as $path => $v) {
            $now = is_file("$current/$path") ? (string) file_get_contents("$current/$path") : null;
            if ($now !== $v[$expect]) {
                $this->save($data); // the step can never apply again, so drop it
                throw new LouException("Can't " . $from . ' "' . $entry['label'] . '": the page was changed since then (by a publish, an assistant or another tab). Your current version is kept.');
            }
        }

        $this->draft->open();
        $dir = $this->draft->siteDir();
        $previous = [];
        foreach ($entry['files'] as $path => $v) {
            $previous[$path] = is_file("$dir/$path") ? (string) file_get_contents("$dir/$path") : null;
            self::put("$dir/$path", $v[$restore]);
        }
        try {
            Site::load($this->draft->app());
        } catch (LouException $e) {
            foreach ($previous as $path => $contents) {
                self::put("$dir/$path", $contents);
            }
            $this->save($data);
            throw $e;
        }
        $this->draft->touch();
        if (!$this->draft->hasChanges()) {
            $this->draft->discard();
        }
        $data[$to][] = $entry;
        $data[$to] = array_slice($data[$to], -self::MAX);
        $this->save($data);
        return ['label' => $entry['label'], 'lang' => $entry['lang']];
    }

    private static function put(string $file, ?string $contents): void
    {
        if ($contents === null) {
            if (is_file($file)) {
                unlink($file);
            }
            return;
        }
        Fs::write($file, $contents);
    }

    /** @return array{undo: list<array>, redo: list<array>} */
    private function load(): array
    {
        $data = is_file($this->file) ? Fs::readJson($this->file) : [];
        return ['undo' => array_values((array) ($data['undo'] ?? [])), 'redo' => array_values((array) ($data['redo'] ?? []))];
    }

    private function save(array $data): void
    {
        Fs::writeJson($this->file, $data);
    }
}
