<?php

declare(strict_types=1);

namespace Lou\Http;

/** Outgoing HTTP (provider APIs, captcha verification, webhooks). */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
