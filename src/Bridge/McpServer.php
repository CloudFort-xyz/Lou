<?php

declare(strict_types=1);

namespace Lou\Bridge;

use Lou\App;
use Lou\Db\Database;
use Lou\Http\Request;
use Lou\Http\Response;
use Lou\LouException;

/**
 * /<endpoint_dir>/mcp.php: the Model Context Protocol (Streamable HTTP transport, plain JSON responses, no SSE)
 * plus a REST fallback for clients without MCP:
 *
 *   POST mcp.php                       JSON-RPC 2.0 (initialize, tools/list, tools/call, ping)
 *   POST mcp.php?tool=read_site_file  {"path": "..."} → {"ok": true, "result": ...}
 *   GET  mcp.php?openapi               OpenAPI 3.1 description of the REST fallback
 *
 * Every request except ?openapi needs "Authorization: Bearer lou_…" (or ?key=lou_… for clients that
 * cannot send headers). Tokens are created in the admin (Assistants) or with `php lou token add`.
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    private const CALLS_PER_10_MIN = 300;
    private const AUTH_FAILURES_PER_15_MIN = 20;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly ?int $now = null,
    ) {
    }

    private string $siteUrl = '';

    public function handle(Request $req): Response
    {
        $this->siteUrl = $this->app->siteUrl($req);
        if (!$this->originAllowed($req->header('origin'))) {
            return Response::json(403, ['error' => 'Origin not allowed']);
        }
        if ($req->method === 'GET' && array_key_exists('openapi', $req->query)) {
            return Response::json(200, $this->openApi());
        }
        if ($req->method !== 'POST') {
            // no server-initiated SSE stream: the spec says answer 405 to GET
            return new Response(405, '', ['Allow' => 'POST']);
        }

        $ipKey = 'mcp-auth|' . substr(hash('sha256', $req->ip), 0, 32);
        if ($this->count($ipKey, 900) >= self::AUTH_FAILURES_PER_15_MIN) {
            return Response::json(429, ['error' => 'Too many failed attempts, try again later']);
        }
        $token = $this->bearer($req);
        $caller = $token === '' ? null : (new Tokens($this->app->varDir . '/tokens.json'))->verify($token);
        if ($caller === null) {
            $this->hit($ipKey);
            return new Response(401, (string) json_encode(['error' => 'A valid bridge token is required']), [
                'Content-Type' => 'application/json', 'WWW-Authenticate' => 'Bearer realm="api"']);
        }
        $caller = 'llm:' . $caller;

        $input = json_decode($req->body, true);
        if (isset($req->query['tool'])) {
            return $this->rest($caller, (string) $req->query['tool'], trim($req->body) === '' ? [] : ($input ?? $req->body));
        }
        if (!is_array($input)) {
            return Response::json(400, self::error(null, -32700, 'Parse error: the body must be JSON-RPC 2.0'));
        }

        // a single message or a batch (array of messages)
        $batch = array_is_list($input) && $input !== [];
        $out = [];
        foreach ($batch ? $input : [$input] as $msg) {
            $reply = $this->message($caller, $msg);
            if ($reply !== null) {
                $out[] = $reply;
            }
        }
        if (!$out) {
            return new Response(202); // only notifications / responses
        }
        return Response::json(200, $batch ? $out : $out[0]);
    }

    // ---------------------------------------------------------------- JSON-RPC

    private function message(string $caller, mixed $msg): ?array
    {
        if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, -32600, 'Invalid request');
        }
        $id = $msg['id'] ?? null;
        $isNotification = !array_key_exists('id', $msg);
        if (!isset($msg['method'])) {
            return null; // a response from the client (we never send requests); ignore
        }
        $method = (string) $msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        if ($isNotification) {
            return null; // notifications/initialized, notifications/cancelled… need no answer
        }
        return match ($method) {
            'initialize' => self::result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? '', self::PROTOCOL_VERSIONS, true)
                    ? $params['protocolVersion'] : self::PROTOCOL_VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'lou', 'title' => 'Lou: ' . $this->app->config['name'], 'version' => App::VERSION],
                'instructions' => 'This is the Lou CMS bridge for "' . $this->app->config['name'] . '". Call describe_site first. '
                    . 'All changes are staged; the site owner reviews a preview and approves before anything goes live.',
            ]),
            'ping' => self::result($id, (object) []),
            'tools/list' => self::result($id, ['tools' => Tools::definitions()]),
            'tools/call' => $this->toolsCall($caller, $id, $params),
            default => self::error($id, -32601, "Method not found: $method"),
        };
    }

    private function toolsCall(string $caller, mixed $id, array $params): array
    {
        $name = Tools::resolve($params['name'] ?? null);
        if ($name === null) {
            return self::error($id, -32602, 'Unknown tool: ' . (is_string($params['name'] ?? null) ? $params['name'] : '(none)'));
        }
        // the spec says params.arguments; tolerate clients that use another key or put them next to name
        $args = $params['arguments'] ?? $params['input'] ?? $params['parameters'] ?? $params['args']
            ?? array_diff_key($params, ['name' => 1, '_meta' => 1, 'arguments' => 1]);
        try {
            $result = $this->call($caller, $name, $args);
            $text = is_string($result) ? $result : (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return self::result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]);
        } catch (LouException $e) {
            if (str_contains($e->getMessage(), 'Received: ')) {
                // what the client actually sent (names and types only), so odd clients can be diagnosed from var/error.log
                @file_put_contents($this->app->varDir . '/error.log', gmdate('c') . " mcp $name: unusable arguments; params were "
                    . Tools::shape($params) . ', arguments ' . Tools::shape($args) . "\n", FILE_APPEND | LOCK_EX);
            }
            // tool errors are results the model can read and fix, not protocol errors
            return self::result($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        }
    }

    private function call(string $caller, string $name, mixed $args): string|array
    {
        $key = 'mcp-call|' . substr(hash('sha256', $caller), 0, 32);
        if ($this->count($key, 600) >= self::CALLS_PER_10_MIN) {
            throw new LouException('Rate limit reached (' . self::CALLS_PER_10_MIN . ' calls per 10 minutes). Wait a few minutes.');
        }
        $this->hit($key);
        return (new Tools($this->app, $this->db, $caller, $this->siteUrl))->call($name, $args);
    }

    private static function result(mixed $id, array|object $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    // ---------------------------------------------------------------- REST fallback

    private function rest(string $caller, string $tool, mixed $args): Response
    {
        if (Tools::resolve($tool) === null) {
            return Response::json(404, ['ok' => false, 'error' => "Unknown tool '$tool'"]);
        }
        try {
            return Response::json(200, ['ok' => true, 'result' => $this->call($caller, $tool, $args)]);
        } catch (LouException $e) {
            return Response::json(422, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function openApi(): array
    {
        $paths = [];
        foreach (Tools::definitions() as $t) {
            $paths[$this->app->endpoint('mcp.php') . '?tool=' . $t['name']] = ['post' => [
                'operationId' => $t['name'],
                'summary' => $t['description'],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $t['inputSchema']]]],
                'responses' => [
                    '200' => ['description' => 'Result', 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'properties' => ['ok' => ['type' => 'boolean'], 'result' => new \stdClass()]]]]],
                    '422' => ['description' => 'The operation was refused; "error" explains why'],
                ],
            ]];
        }
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Site bridge: ' . $this->app->config['name'], 'version' => '1',
                'description' => 'Call describe_site first. All changes are staged until a human approves them.'],
            'servers' => [['url' => $this->siteUrl ?: $this->app->siteUrl()]],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
            'security' => [['bearer' => []]],
            'paths' => $paths,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function bearer(Request $req): string
    {
        if (preg_match('/^Bearer\s+(\S+)$/i', $req->header('authorization'), $m)) {
            return $m[1];
        }
        return is_string($req->query['key'] ?? null) ? $req->query['key'] : '';
    }

    /** Browsers send Origin; desktop/CLI clients don't. Block foreign web pages (DNS rebinding, CSRF). */
    private function originAllowed(string $origin): bool
    {
        if ($origin === '') {
            return true;
        }
        $base = parse_url((string) $this->app->config['base_url']);
        $o = parse_url($origin);
        if (!$base || !$o || !isset($o['host'])) {
            return false;
        }
        $allowed = [strtolower(($base['scheme'] ?? '') . '://' . ($base['host'] ?? '') . (isset($base['port']) ? ':' . $base['port'] : ''))];
        foreach ((array) ($this->app->config['bridge']['allowed_origins'] ?? []) as $extra) {
            $allowed[] = strtolower(rtrim((string) $extra, '/'));
        }
        return in_array(strtolower(rtrim($origin, '/')), $allowed, true);
    }

    private function time(): int
    {
        return $this->now ?? time();
    }

    private function count(string $key, int $window): int
    {
        return (int) $this->db->run('SELECT COUNT(*) FROM rate_limits WHERE key = ? AND ts > ?', [$key, $this->time() - $window])->fetchColumn();
    }

    private function hit(string $key): void
    {
        $this->db->run('INSERT INTO rate_limits (key, ts) VALUES (?, ?)', [$key, $this->time()]);
    }
}
