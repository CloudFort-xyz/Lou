<?php

declare(strict_types=1);

namespace Lou\Forms;

use Lou\App;
use Lou\Db\Database;
use Lou\Env;
use Lou\Http\Request;
use Lou\Http\Response;
use Lou\Integrations;

/** Entry point used by public/_lou/submit.php. */
final class Endpoint
{
    public static function run(string $root): void
    {
        try {
            $app = new App($root);
            $processor = new FormProcessor($app, new Database($app->databaseFile()), new Integrations($app, Env::load($app)));
            $processor->handle(Request::fromGlobals())->send();
        } catch (\Throwable $e) {
            @file_put_contents($root . '/var/error.log', gmdate('c') . ' submit.php: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            Response::json(500, ['ok' => false, 'message' => 'Server error'])->send();
        }
    }
}
