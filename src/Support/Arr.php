<?php

declare(strict_types=1);

namespace Lou\Support;

final class Arr
{
    /** Read a nested value with a dot path: get($a, 'hero.image.src'). */
    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        $cur = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return $default;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }
}
