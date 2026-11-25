<?php

declare(strict_types=1);

namespace Lou\Admin;

interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    /** New session id (call on login to prevent fixation). */
    public function regenerate(): void;

    public function destroy(): void;
}
