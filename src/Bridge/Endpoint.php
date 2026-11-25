<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\App;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\Http\Response;

/** Entry point used by public/<endpoint_dir>/mcp.php. */
final class Endpoint
{
    public static function run(string $root): void
    {
        try {
            $app = new App($root);
            (new McpServer($app, new Database($app->databaseFile())))->handle(Request::fromGlobals())->send();
        } catch (\Throwable $e) {
            @file_put_contents($root . '/var/error.log', gmdate('c') . ' mcp.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
            Response::json(500, ['error' => 'Server error, details are in var/error.log'])->send();
        }
    }
}
