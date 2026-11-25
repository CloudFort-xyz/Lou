<?php

declare(strict_types=1);

namespace Lou\Admin;

/** In-memory session for tests and the CLI. */
final class ArraySession implements SessionStore
{
    public int $regenerated = 0;

    public function __construct(public array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function regenerate(): void
    {
        $this->regenerated++;
    }

    public function destroy(): void
    {
        $this->data = [];
    }
}
