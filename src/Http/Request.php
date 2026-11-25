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
        public readonly bool $https = false,
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
            https: in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
                || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
        );
    }

    /** scheme://host[:port] this request was made to, or '' when the Host header is missing or malformed. */
    public function origin(): string
    {
        $host = strtolower($this->header('host'));
        if (!preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/', $host)) {
            return '';
        }
        return ($this->https ? 'https://' : 'http://') . $host;
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
