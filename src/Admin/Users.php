<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\LouException;
use Lou\Support\Fs;

/**
 * Admin users in var/users.json (0600, password hashes only). Managed on the Users page or with `php lou user …`.
 *
 * Roles, from least to most: "contributor" (edits in a draft and submits it, can't publish or see submissions),
 * "editor" (also publishes and sees form submissions) and "admin" (also users, assistants, settings, builds, backups).
 *
 * A record: hash ('' until an invited person sets a password), role, created, and optionally email, sv (session
 * version, see sessionVersion()), last_login, link (a pending invite or reset link: SHA-256 of the token, purpose,
 * expiry, who made it) and prefs.
 */
final class Users
{
    public const ROLES = ['contributor', 'editor', 'admin'];
    private const MIN_PASSWORD = 10;
    /** How long links stay valid: invites and resets an admin sends, and "Forgot password?" links. */
    public const INVITE_TTL = 172800;
    public const FORGOT_TTL = 3600;

    public function __construct(private readonly string $file)
    {
    }

    /** True when $role may do what $need may do (admin ⊃ editor ⊃ contributor). */
    public static function atLeast(string $role, string $need): bool
    {
        $rank = array_flip(self::ROLES);
        return isset($rank[$role], $rank[$need]) && $rank[$role] >= $rank[$need];
    }

    /** @return array<string, array{hash: string, role: string, created: string, email?: string, sv?: int, last_login?: string, link?: array{hash: string, purpose: string, expires: int, by: string}, prefs?: array<string, string>}> */
    public function all(): array
    {
        return is_file($this->file) ? Fs::readJson($this->file) : [];
    }

    /** Create a user with a password, or reset an existing user's password and role (keeping email and preferences). */
    public function add(string $name, string $password, string $role = 'editor', ?string $email = null): void
    {
        self::validate($name, $password, $role);
        if ($email !== null && $email !== '') {
            self::validateEmail($email);
        }
        $users = $this->all();
        $key = strtolower($name);
        $old = $users[$key] ?? [];
        if (($old['role'] ?? null) === 'admin' && $role !== 'admin' && $this->adminCount() <= 1) {
            throw new LouException('Make someone else an administrator before demoting the last one.');
        }
        $users[$key] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role] + $old + ['created' => gmdate('c')];
        if ($email !== null) {
            $users[$key]['email'] = $email;
        }
        unset($users[$key]['link']);
        if ($old) {
            // a new password signs out the sessions started with the old one
            $users[$key]['sv'] = (int) ($old['sv'] ?? 0) + 1;
        }
        $this->save($users);
    }

    /**
     * Invite someone: the user exists right away (with no password, so nobody can log in as them) and the returned
     * token, sent as a link, lets them choose their own password within INVITE_TTL.
     *
     * @return string the token for the link (shown once, only its hash is stored)
     */
    public function invite(string $name, string $email, string $role, string $by, int $now): string
    {
        self::validateNameAndRole($name, $role);
        self::validateEmail($email);
        $users = $this->all();
        $key = strtolower($name);
        if (isset($users[$key])) {
            throw new LouException('There is already a user with that name.');
        }
        $users[$key] = ['hash' => '', 'role' => $role, 'created' => gmdate('c', $now), 'email' => $email];
        $this->save($users);
        return $this->link($key, 'invite', $by, $now, self::INVITE_TTL);
    }

    /**
     * A one-time link that lets the user set a new password (replaces any earlier link of theirs).
     *
     * @return string the token
     */
    public function link(string $name, string $purpose, string $by, int $now, int $ttl): string
    {
        $users = $this->all();
        $key = strtolower($name);
        if (!isset($users[$key])) {
            throw new LouException("No such user: $name");
        }
        $token = bin2hex(random_bytes(32));
        $users[$key]['link'] = ['hash' => hash('sha256', $token), 'purpose' => $purpose, 'expires' => $now + $ttl, 'by' => $by];
        $this->save($users);
        return $token;
    }

    /** @return string|null the user a valid, unexpired link token belongs to */
    public function findByToken(string $token, int $now): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        foreach ($this->all() as $name => $u) {
            if (isset($u['link']) && hash_equals($u['link']['hash'], $hash) && $u['link']['expires'] >= $now) {
                return $name;
            }
        }
        return null;
    }

    /** Set a password with a link token. The link works once; every older session of the user ends. */
    public function setPasswordWithToken(string $token, string $password, int $now): string
    {
        $name = $this->findByToken($token, $now) ?? throw new LouException('This link has expired or was already used. Ask an administrator for a new one.');
        $users = $this->all();
        self::validate($name, $password, $users[$name]['role']);
        $users[$name]['hash'] = password_hash($password, PASSWORD_DEFAULT);
        $users[$name]['sv'] = (int) ($users[$name]['sv'] ?? 0) + 1;
        unset($users[$name]['link']);
        $this->save($users);
        return $name;
    }

    /** @return string|null the user with this name or email address (case-insensitive) */
    public function find(string $nameOrEmail): ?string
    {
        $needle = strtolower(trim($nameOrEmail));
        foreach ($this->all() as $name => $u) {
            if ($name === $needle || ($needle !== '' && strtolower((string) ($u['email'] ?? '')) === $needle)) {
                return $name;
            }
        }
        return null;
    }

    /** @return array{hash: string, role: string, created: string, email?: string, sv?: int, last_login?: string, link?: array{hash: string, purpose: string, expires: int, by: string}, prefs?: array<string, string>}|null */
    public function get(string $name): ?array
    {
        return $this->all()[strtolower($name)] ?? null;
    }

    /** The user's current role, or null when the user no longer exists. Read on every request, so changes apply at once. */
    public function role(string $name): ?string
    {
        return $this->get($name)['role'] ?? null;
    }

    /**
     * The user's session version. Sessions remember the version they were started with; bumping it (password change,
     * "sign out everywhere", removal) makes every older session invalid on its next request.
     */
    public function sessionVersion(string $name): int
    {
        return (int) ($this->get($name)['sv'] ?? 0);
    }

    /** @return int the new session version */
    public function bumpSessions(string $name): int
    {
        return $this->update($name, function (array $u): array {
            $u['sv'] = (int) ($u['sv'] ?? 0) + 1;
            return $u;
        })['sv'];
    }

    public function touchLogin(string $name, int $now): void
    {
        $this->update($name, fn (array $u): array => ['last_login' => gmdate('c', $now)] + $u);
    }

    public function adminCount(): int
    {
        return count(array_filter($this->all(), fn (array $u): bool => ($u['role'] ?? '') === 'admin'));
    }

    /** Change a user's role. The last administrator can't be demoted, so the site always has someone who can manage it. */
    public function setRole(string $name, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new LouException('Role must be one of: ' . implode(', ', self::ROLES));
        }
        $count = $this->adminCount();
        $this->update($name, function (array $u) use ($role, $count): array {
            if ($u['role'] === 'admin' && $role !== 'admin' && $count <= 1) {
                throw new LouException('Make someone else an administrator before demoting the last one.');
            }
            $u['role'] = $role;
            return $u;
        });
    }

    /** Set or clear ('') a user's email address, where invites and password links go. */
    public function setEmail(string $name, string $email): void
    {
        if ($email !== '') {
            self::validateEmail($email);
        }
        $this->update($name, function (array $u) use ($email): array {
            unset($u['email']);
            return $email !== '' ? $u + ['email' => $email] : $u;
        });
    }

    // ------------------------------------------------------------------ two-step login (TOTP)

    public function hasTotp(string $name): bool
    {
        return isset($this->get($name)['totp']['secret']);
    }

    /**
     * Turn on two-step login with a secret the user's app has proven it knows (the code for $step was right).
     *
     * @return list<string> the recovery codes, shown once (only hashes are kept)
     */
    public function enableTotp(string $name, string $secret, int $step, int $now): array
    {
        $codes = self::newRecoveryCodes();
        $this->update($name, function (array $u) use ($secret, $step, $now, $codes): array {
            $u['totp'] = ['secret' => $secret, 'since' => gmdate('c', $now), 'step' => $step];
            $u['recovery'] = array_map(self::recoveryHash(...), $codes);
            return $u;
        });
        return $codes;
    }

    /** @return list<string> new recovery codes; the old ones stop working */
    public function renewRecoveryCodes(string $name): array
    {
        $codes = self::newRecoveryCodes();
        $this->update($name, fn (array $u): array => ['recovery' => array_map(self::recoveryHash(...), $codes)] + $u);
        return $codes;
    }

    public function recoveryLeft(string $name): int
    {
        return count($this->get($name)['recovery'] ?? []);
    }

    public function disableTotp(string $name): void
    {
        $this->update($name, function (array $u): array {
            unset($u['totp'], $u['recovery']);
            return $u;
        });
    }

    /**
     * Check the second step of a login: a code from the app (each one works once) or a recovery code (each one works
     * once). @return string|null 'app' or 'recovery' on success
     */
    public function verifySecondFactor(string $name, string $code, int $now): ?string
    {
        $u = $this->get($name);
        if (!isset($u['totp']['secret'])) {
            return null;
        }
        $step = Totp::verify($u['totp']['secret'], $code, $now, (int) ($u['totp']['step'] ?? -1));
        if ($step !== null) {
            $this->update($name, function (array $u) use ($step): array {
                $u['totp']['step'] = $step;
                return $u;
            });
            return 'app';
        }
        $hash = self::recoveryHash($code);
        foreach ($u['recovery'] ?? [] as $i => $h) {
            if (hash_equals($h, $hash)) {
                $this->update($name, function (array $u) use ($i): array {
                    unset($u['recovery'][$i]);
                    $u['recovery'] = array_values($u['recovery']);
                    return $u;
                });
                return 'recovery';
            }
        }
        return null;
    }

    /** @return list<string> ten codes like 4f2a9-c81b0 (40 random bits each) */
    private static function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $hex = bin2hex(random_bytes(5));
            $codes[] = substr($hex, 0, 5) . '-' . substr($hex, 5);
        }
        return $codes;
    }

    private static function recoveryHash(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[^0-9a-f]/i', '', $code)));
    }

    public function prefs(string $name): Prefs
    {
        return Prefs::fromArray((array) ($this->all()[strtolower($name)]['prefs'] ?? []));
    }

    public function setPrefs(string $name, Prefs $prefs): void
    {
        $this->update($name, function (array $u) use ($prefs): array {
            unset($u['prefs']);
            return $prefs->toArray() ? $u + ['prefs' => $prefs->toArray()] : $u;
        });
    }

    /** Change a user's own password; the current one must be right. Every other session of theirs ends. */
    public function changePassword(string $name, string $current, string $new): void
    {
        if ($this->verify($name, $current) === null) {
            throw new LouException('The current password is wrong.');
        }
        self::validate($name, $new, (string) $this->role($name));
        $this->update($name, function (array $u) use ($new): array {
            $u['hash'] = password_hash($new, PASSWORD_DEFAULT);
            $u['sv'] = (int) ($u['sv'] ?? 0) + 1;
            return $u;
        });
    }

    /** @throws LouException when the name, password or role is not acceptable */
    public static function validate(string $name, string $password, string $role = 'editor'): void
    {
        self::validateNameAndRole($name, $role);
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new LouException('Passwords must be at least ' . self::MIN_PASSWORD . ' characters');
        }
        if (PasswordPolicy::isCommon($password, $name)) {
            throw new LouException('This password is too easy to guess, choose another one.');
        }
    }

    private static function validateNameAndRole(string $name, string $role): void
    {
        if (!preg_match('/^[a-z0-9._@-]{2,64}$/i', $name)) {
            throw new LouException('User names may contain letters, digits and . _ @ - (2–64 characters)');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new LouException('Role must be one of: ' . implode(', ', self::ROLES));
        }
    }

    public static function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new LouException('Enter a valid email address.');
        }
    }

    /** Remove a user. Their sessions end on their next request. The last administrator can't be removed. */
    public function remove(string $name): void
    {
        $users = $this->all();
        if (!isset($users[strtolower($name)])) {
            throw new LouException("No such user: $name");
        }
        if ($users[strtolower($name)]['role'] === 'admin' && $this->adminCount() <= 1) {
            throw new LouException('Make someone else an administrator before removing the last one.');
        }
        unset($users[strtolower($name)]);
        $this->save($users);
    }

    /** @return string|null the role on success */
    public function verify(string $name, string $password): ?string
    {
        $user = $this->all()[strtolower($name)] ?? null;
        // constant-ish time: always run one password_verify (also for invited users, who have no password yet)
        $hash = ($user['hash'] ?? '') !== '' ? $user['hash'] : password_hash('not-a-user', PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash);
        if (!$ok || $user === null || $user['hash'] === '') {
            return null;
        }
        if (password_needs_rehash($user['hash'], PASSWORD_DEFAULT)) {
            $this->update($name, fn (array $u): array => ['hash' => password_hash($password, PASSWORD_DEFAULT)] + $u);
        }
        return $user['role'];
    }

    /** @return array<string, mixed> the updated record */
    private function update(string $name, callable $fn): array
    {
        $users = $this->all();
        $key = strtolower($name);
        if (!isset($users[$key])) {
            throw new LouException("No such user: $name");
        }
        $users[$key] = $fn($users[$key]);
        $this->save($users);
        return $users[$key];
    }

    private function save(array $users): void
    {
        ksort($users);
        Fs::writeJson($this->file, $users);
        @chmod($this->file, 0600);
    }
}
