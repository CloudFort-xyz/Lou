<?php

declare(strict_types=1);

namespace Lou\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public static function json(int $status, array $data): self
    {
        return new self($status, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function redirect(string $url): self
    {
        return new self(303, '', ['Location' => $url]);
    }

    public static function html(int $status, string $html): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $k => $v) {
            header("$k: " . str_replace(["\r", "\n"], '', $v));
        }
        echo $this->body;
    }
}
