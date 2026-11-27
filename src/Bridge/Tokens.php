<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\LouException;
use Lou\Support\Fs;

/** Bearer tokens for the LLM bridge (var/tokens.json, SHA-256 hashes only). Created/revoked in the admin or CLI. */
final class Tokens
{
    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, array{name: string, hash: string, created: string, last_used: ?string, by?: string}> */
    public function all(): array
    {
        return is_file($this->file) ? Fs::readJson($this->file) : [];
    }

    /** @return string the token, shown once */
    public function create(string $name, ?string $by = null): string
    {
        $name = trim(strip_tags($name));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new LouException('Give the token a name (max 60 characters), e.g. "Claude – Bence"');
        }
        $token = 'lou_' . bin2hex(random_bytes(24));
        $all = $this->all();
        $all[substr(hash('sha256', $token), 0, 12)] = ['name' => $name, 'hash' => hash('sha256', $token), 'created' => gmdate('c'), 'last_used' => null] + ($by !== null ? ['by' => $by] : []);
        $this->save($all);
        return $token;
    }

    public function revoke(string $id): void
    {
        $all = $this->all();
        unset($all[$id]);
        $this->save($all);
    }

    /** @return string|null the token's name */
    public function verify(string $token): ?string
    {
        if (!preg_match('/^lou_[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $all = $this->all();
        foreach ($all as $id => $t) {
            if (hash_equals($t['hash'], $hash)) {
                if (($t['last_used'] ?? '') < gmdate('c', time() - 300)) {
                    $all[$id]['last_used'] = gmdate('c');
                    $this->save($all);
                }
                return $t['name'];
            }
        }
        return null;
    }

    private function save(array $all): void
    {
        Fs::writeJson($this->file, $all);
        @chmod($this->file, 0600);
    }
}
