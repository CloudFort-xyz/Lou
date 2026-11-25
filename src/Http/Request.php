<?php

declare(strict_types=1);

namespace Lou\Http;

/** The parts of an incoming request the endpoints need. Constructible in tests. */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly string $ip = '',
        public readonly int $contentLength = 0,
        public readonly array $files = [],
        public readonly string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            query: $_GET,
            post: $_POST,
            headers: $headers,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            contentLength: (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
            files: $_FILES,
            // raw body only for JSON requests (MCP / REST); form posts arrive in $_POST
            body: str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json') ? (string) file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024) : '',
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json');
    }

    public function input(string $key): string
    {
        $v = $this->post[$key] ?? '';
        return is_string($v) ? $v : '';
    }
}
