<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the Lou\ namespace. No Composer required.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Lou\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
