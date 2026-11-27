<?php

declare(strict_types=1);

namespace Lou\Db;

use Lou\Support\Fs;

/**
 * The single SQLite file in var/. Schema migrations are tracked with PRAGMA user_version.
 */
final class Database
{
    private const MIGRATIONS = [
        1 => <<<SQL
            CREATE TABLE submissions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                form        TEXT NOT NULL,
                lang        TEXT NOT NULL,
                data        TEXT NOT NULL,             -- JSON of validated fields
                status      TEXT NOT NULL,             -- ok | partial | failed
                results     TEXT NOT NULL DEFAULT '[]',-- JSON: per-action result
                ip_hash     TEXT,
                user_agent  TEXT,
                created_at  TEXT NOT NULL
            );
            CREATE INDEX submissions_form ON submissions (form, created_at);
            CREATE INDEX submissions_status ON submissions (status);

            CREATE TABLE subscribers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                email       TEXT NOT NULL,
                name        TEXT,
                provider    TEXT NOT NULL,
                list        TEXT NOT NULL DEFAULT '',
                status      TEXT NOT NULL,             -- synced | failed
                error       TEXT,
                created_at  TEXT NOT NULL,
                updated_at  TEXT NOT NULL,
                UNIQUE (email, provider, list)
            );

            CREATE TABLE rate_limits (
                key TEXT NOT NULL,
                ts  INTEGER NOT NULL
            );
            CREATE INDEX rate_limits_key ON rate_limits (key, ts);

            CREATE TABLE audit_log (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                at      TEXT NOT NULL,
                source  TEXT NOT NULL,                 -- cli | editor | llm | form
                actor   TEXT,
                action  TEXT NOT NULL,
                detail  TEXT
            );
            SQL,
        // OAuth for the MCP bridge (Lou\Bridge\OAuth); secrets, codes and tokens are SHA-256 hashes
        2 => <<<SQL
            CREATE TABLE oauth_clients (
                id           TEXT PRIMARY KEY,
                secret_hash  TEXT,                     -- NULL: public client (PKCE only)
                name         TEXT NOT NULL,
                redirect_uris TEXT NOT NULL DEFAULT '[]',
                auth_method  TEXT NOT NULL,
                dynamic      INTEGER NOT NULL,         -- 1: registered itself (RFC 7591), 0: made by an admin
                created_by   TEXT,
                created_at   TEXT NOT NULL
            );
            CREATE TABLE oauth_codes (
                hash         TEXT PRIMARY KEY,
                client_id    TEXT NOT NULL,
                user         TEXT NOT NULL,
                redirect_uri TEXT NOT NULL,
                challenge    TEXT NOT NULL DEFAULT '',
                resource     TEXT NOT NULL DEFAULT '',
                expires      INTEGER NOT NULL
            );
            CREATE TABLE oauth_grants (
                id           TEXT PRIMARY KEY,
                client_id    TEXT NOT NULL,
                user         TEXT NOT NULL,
                name         TEXT NOT NULL,            -- the assistant's name in drafts and the audit log
                sv           INTEGER NOT NULL DEFAULT 0,
                created_at   TEXT NOT NULL,
                last_used    TEXT,
                UNIQUE (client_id, user)
            );
            CREATE TABLE oauth_tokens (
                hash         TEXT PRIMARY KEY,
                grant_id     TEXT NOT NULL,
                kind         TEXT NOT NULL,            -- access | refresh
                expires      INTEGER NOT NULL,
                used_at      INTEGER                   -- refresh tokens: when it was rotated
            );
            CREATE INDEX oauth_tokens_grant ON oauth_tokens (grant_id);
            SQL,
    ];

    public readonly \PDO $pdo;

    public function __construct(string $file)
    {
        Fs::ensureDir(dirname($file));
        $this->pdo = new \PDO('sqlite:' . $file, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON;');
        $this->migrate();
    }

    private function migrate(): void
    {
        $version = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        foreach (self::MIGRATIONS as $v => $sql) {
            if ($v > $version) {
                $this->pdo->beginTransaction();
                $this->pdo->exec($sql);
                $this->pdo->exec("PRAGMA user_version = $v");
                $this->pdo->commit();
            }
        }
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function audit(string $source, string $action, ?string $detail = null, ?string $actor = null): void
    {
        $this->run('INSERT INTO audit_log (at, source, actor, action, detail) VALUES (?, ?, ?, ?, ?)',
            [gmdate('c'), $source, $actor, $action, $detail]);
    }
}
