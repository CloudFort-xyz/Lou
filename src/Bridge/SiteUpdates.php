<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\App;
use Lou\Content\ChangeList;
use Lou\Content\History;
use Lou\Content\Workspace;
use Lou\Db\Database;
use Lou\LouException;
use Lou\Support\Diff;

/**
 * The site's publish history for assistants (read-only): when updates went live, what changed, who submitted and
 * who published them. Built from what Lou already keeps: History (var/history/, one entry per publish), staging
 * (what is waiting for review) and a few audit-log actions that change the live site without a publish entry
 * (rollback, rebuild, Lou updates, restores, rejected staging).
 *
 * Only site files are shown (the same files an assistant can read anyway). Never tokens, passwords, settings
 * from .env.php or var/secrets.json, form submissions or subscribers.
 */
final class SiteUpdates
{
    /** Audit-log actions that tell an assistant something happened to the live site or to the review queue. */
    private const EVENTS = [
        'rollback'       => 'The live site was rolled back to an earlier build (content files unchanged).',
        'rebuild'        => 'The live site was rebuilt (e.g. after a Lou update), content unchanged.',
        'update'         => 'Lou itself was updated.',
        'restore'        => 'A backup was restored into staging for review.',
        'reject-staging' => 'Changes waiting on staging were rejected (discarded).',
    ];

    public const MAX_LIMIT = 100;
    private const DIFF_LIMIT = 20_000;

    public function __construct(private readonly App $app, private readonly Database $db)
    {
    }

    /** @return array{name: string, type: string} "llm:Claude" → assistant Claude, "user:bence" or "bence" → person bence */
    public static function actor(?string $by): array
    {
        $by = (string) $by;
        if (str_starts_with($by, 'llm:')) {
            return ['name' => substr($by, 4), 'type' => 'assistant'];
        }
        return ['name' => preg_replace('/^user:/', '', $by) ?: 'unknown', 'type' => 'person'];
    }

    /** Parse a "since" value: a date, a date-time or a relative time ("7 days ago", "yesterday"). null = no limit. */
    public static function since(?string $since): ?int
    {
        if ($since === null || trim($since) === '') {
            return null;
        }
        $t = strtotime(trim($since) . (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($since)) ? ' 00:00:00 UTC' : ''));
        if ($t === false) {
            throw new LouException("Could not read since \"" . mb_substr($since, 0, 40) . "\". Use a date like 2026-11-20, a date-time like 2026-11-20T08:00:00Z, or e.g. \"7 days ago\".");
        }
        return $t;
    }

    public static function limit(mixed $limit): int
    {
        $n = is_numeric($limit) ? (int) $limit : 20;
        return max(1, min(self::MAX_LIMIT, $n));
    }

    /** Newest first. */
    public function list(int $limit = 20, ?int $since = null, ?string $path = null): array
    {
        $history = new History($this->app);
        if ($path !== null) {
            $path = PathPolicy::normalise($path);
        }
        $updates = [];
        $total = 0;
        foreach ($history->all($path) as $e) {
            if ($since !== null && strtotime($e['at']) < $since) {
                break; // entries are newest first
            }
            $total++;
            if (count($updates) < $limit) {
                $updates[] = $this->summary($e);
            }
        }
        $out = [
            'history_enabled' => $history->enabled(),
            'times'           => 'All times are UTC (ISO 8601).',
            'updates'         => $updates,
            'more'            => max(0, $total - count($updates)),
        ];
        if ($path === null) {
            $out['other_events'] = $this->events($limit, $since);
            $out['waiting_for_review'] = $this->waiting();
        }
        $out['note'] = $updates
            ? 'Call get_site_update with an id for the changed fields, and with diff: true for line diffs.'
            : ($history->enabled() ? 'No published updates match.' : 'Publish history is turned off in config.php (\'history\' => false).');
        return $out;
    }

    /** One publish: its people, plain-language changes and, on request, unified diffs. */
    public function get(string $id, ?string $path = null, bool $diff = false): array
    {
        $history = new History($this->app);
        $id = trim($id);
        if (in_array(strtolower($id), ['latest', 'last', 'newest'], true)) {
            $id = (string) ($history->all()[0]['id'] ?? '');
            if ($id === '') {
                throw new LouException('Nothing has been published yet (or history is turned off).');
            }
        }
        try {
            $e = $history->get($id);
        } catch (LouException) {
            throw new LouException("Unknown update id \"" . mb_substr($id, 0, 40) . "\". list_site_updates lists the ids (like 20261126-101500-123456); \"latest\" works too.");
        }
        $files = $e['files'];
        if ($path !== null) {
            $path = PathPolicy::normalise($path);
            if (!isset($files[$path])) {
                throw new LouException("That update did not change $path. It changed: " . implode(', ', array_keys($files)));
            }
            $files = [$path => $files[$path]];
        }
        $changes = [];
        foreach ($files as $file => $kind) {
            $binary = PathPolicy::isBinary($file);
            $old = $binary ? null : $history->version($id, 'before', $file);
            $new = $binary ? null : $history->version($id, 'after', $file);
            $item = ChangeList::item($file, self::kind($kind), $old, $new);
            $change = ['path' => $file, 'kind' => $item['kind'], 'label' => $item['label'], 'group' => ChangeList::GROUPS[$item['group']]];
            $fields = array_map(fn ($f) => ['field' => $f['name'], 'old' => $f['old'], 'new' => $f['new']], [...$item['fields'], ...$item['rest']]);
            if ($fields) {
                $change['fields'] = $fields;
            }
            if ($diff) {
                $d = $binary ? "(binary file $kind)\n" : Diff::unified($old ?? '', $new ?? '');
                $change['diff'] = strlen($d) > self::DIFF_LIMIT
                    ? mb_strcut($d, 0, self::DIFF_LIMIT) . "\n… (diff cut at " . self::DIFF_LIMIT . " bytes; ask for one path, or read_site_file for the current version)\n"
                    : $d;
            }
            $changes[] = $change;
        }
        return $this->summary($e, false) + ['changes' => $changes,
            'note' => $diff ? 'Diffs are old → new versions of each file at that publish.' : 'Add diff: true (optionally with a path) for line diffs.'];
    }

    private function summary(array $e, bool $withFiles = true): array
    {
        $subs = array_map(fn ($s) => ['by' => self::actor($s['by'] ?? null), 'summary' => (string) ($s['summary'] ?? ''), 'submitted_at' => $s['at'] ?? null]
            + (isset($s['files']) ? ['files' => $s['files']] : []), $e['submissions'] ?? []);
        $out = [
            'id'           => $e['id'],
            'published_at' => $e['at'],
            'published_by' => self::actor($e['by'] ?? null),
            'submissions'  => $subs,
        ];
        if (isset($e['build'])) {
            $out['build'] = $e['build'];
        }
        if ($withFiles) {
            $out['files'] = array_map(fn ($kind) => self::kind($kind), $e['files']);
        }
        return $out;
    }

    private static function kind(string $kind): string
    {
        return match ($kind) {
            'modified' => 'changed',
            default    => $kind,
        };
    }

    private function events(int $limit, ?int $since): array
    {
        $actions = array_keys(self::EVENTS);
        $marks = implode(',', array_fill(0, count($actions), '?'));
        $sql = "SELECT at, actor, action, detail FROM audit_log WHERE (action IN ($marks) OR (action = 'publish' AND detail NOT LIKE '%:%'))"
            . ($since !== null ? ' AND at >= ?' : '') . ' ORDER BY id DESC LIMIT ' . $limit;
        $params = $since !== null ? [...$actions, gmdate('c', $since)] : $actions;
        $out = [];
        foreach ($this->db->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            // a publish with nothing on staging only rebuilt the live site
            $action = $r['action'] === 'publish' ? 'rebuild' : $r['action'];
            $out[] = ['at' => $r['at'], 'what' => $action, 'by' => self::actor($r['actor']), 'meaning' => self::EVENTS[$action],
                'detail' => mb_substr((string) $r['detail'], 0, 300)];
        }
        return $out;
    }

    private function waiting(): ?array
    {
        $staging = Workspace::staging($this->app);
        if (!$staging->exists()) {
            return null;
        }
        $meta = $staging->meta();
        return [
            'files'       => $staging->changedFiles(),
            'submissions' => array_map(fn ($s) => ['by' => self::actor($s['by']), 'summary' => $s['summary'], 'submitted_at' => $s['at'], 'files' => $s['files'] ?? []], $meta['submissions']),
            'note'        => 'Not live yet: a person reviews and publishes these in the admin.',
        ];
    }
}
