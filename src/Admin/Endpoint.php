<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\App;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\Http\Response;

/** Entry point used by public/<endpoint_dir>/admin.php. */
final class Endpoint
{
    public static function run(string $root): void
    {
        try {
            $app = new App($root);
            $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
            $session = new NativeSession($app->varDir . '/sessions', $https);
            $admin = new AdminApp($app, new Database($app->databaseFile()), $session);
            $admin->handle(Request::fromGlobals())->send();
        } catch (\Throwable $e) {
            @file_put_contents($root . '/var/error.log', gmdate('c') . ' admin.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
            Response::html(500, '<!doctype html><title>Error</title><p>Something went wrong. Details are in var/error.log.</p>')->send();
        }
    }
}
