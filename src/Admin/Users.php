<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\LouException;
use Lou\Support\Fs;

/**
 * Admin users in var/users.json (password hashes only). Managed with `php lou user …`.
 * Roles: "editor" (edit content, publish, see submissions) and "admin" (also builds, backups, redirects, deletions).
 */
final class Users
{
    public const ROLES = ['editor', 'admin'];
    private const MIN_PASSWORD = 10;

    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, array{hash: string, role: string, created: string, prefs?: array<string, string>}> */
    public function all(): array
    {
        return is_file($this->file) ? Fs::readJson($this->file) : [];
    }

    public function add(string $name, string $password, string $role = 'editor'): void
    {
        self::validate($name, $password, $role);
        $users = $this->all();
        $old = $users[strtolower($name)] ?? [];
        // adding an existing user again resets the password and role, but keeps their preferences
        $users[strtolower($name)] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'created' => $old['created'] ?? gmdate('c')]
            + (isset($old['prefs']) ? ['prefs' => $old['prefs']] : []);
        $this->save($users);
    }

    public function prefs(string $name): Prefs
    {
        return Prefs::fromArray((array) ($this->all()[strtolower($name)]['prefs'] ?? []));
    }

    public function setPrefs(string $name, Prefs $prefs): void
    {
        $users = $this->all();
        if (!isset($users[strtolower($name)])) {
            throw new LouException("No such user: $name");
        }
        $users[strtolower($name)]['prefs'] = $prefs->toArray();
        if (!$users[strtolower($name)]['prefs']) {
            unset($users[strtolower($name)]['prefs']);
        }
        $this->save($users);
    }

    /** Change a user's own password; the current one must be right. */
    public function changePassword(string $name, string $current, string $new): void
    {
        if ($this->verify($name, $current) === null) {
            throw new LouException('The current password is wrong.');
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            throw new LouException('Passwords must be at least ' . self::MIN_PASSWORD . ' characters');
        }
        $users = $this->all();
        $users[strtolower($name)]['hash'] = password_hash($new, PASSWORD_DEFAULT);
        $this->save($users);
    }

    /** @throws LouException when the name, password or role is not acceptable */
    public static function validate(string $name, string $password, string $role = 'editor'): void
    {
        if (!preg_match('/^[a-z0-9._@-]{2,64}$/i', $name)) {
            throw new LouException('User names may contain letters, digits and . _ @ - (2–64 characters)');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new LouException('Role must be one of: ' . implode(', ', self::ROLES));
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new LouException('Passwords must be at least ' . self::MIN_PASSWORD . ' characters');
        }
    }

    public function remove(string $name): void
    {
        $users = $this->all();
        if (!isset($users[strtolower($name)])) {
            throw new LouException("No such user: $name");
        }
        unset($users[strtolower($name)]);
        $this->save($users);
    }

    /** @return string|null the role on success */
    public function verify(string $name, string $password): ?string
    {
        $user = $this->all()[strtolower($name)] ?? null;
        // constant-ish time: always run one password_verify
        $ok = password_verify($password, $user['hash'] ?? password_hash('not-a-user', PASSWORD_DEFAULT));
        if (!$ok || $user === null) {
            return null;
        }
        if (password_needs_rehash($user['hash'], PASSWORD_DEFAULT)) {
            $users = $this->all();
            $users[strtolower($name)]['hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->save($users);
        }
        return $user['role'];
    }

    private function save(array $users): void
    {
        ksort($users);
        Fs::writeJson($this->file, $users);
        @chmod($this->file, 0600);
    }
}
