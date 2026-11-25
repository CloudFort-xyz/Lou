<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\Support\Fs;

/** PHP sessions, hardened: own save path in var/, strict mode, Secure + HttpOnly + SameSite=Strict, scoped to /_lou/. */
final class NativeSession implements SessionStore
{
    public function __construct(string $savePath, bool $https)
    {
        Fs::ensureDir($savePath);
        @chmod($savePath, 0700);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', '43200');
        session_save_path($savePath);
        session_name($https ? '__Host-lou' : 'lou');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',          // __Host- prefix requires "/"; the cookie is only read by /_lou/ endpoints
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
