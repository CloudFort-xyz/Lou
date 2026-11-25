<?php

declare(strict_types=1);

namespace Lou\Http;

use Lou\LouException;

/** JSON POST to a configured URL, optionally signed with X-Lou-Signature: sha256=<hmac>. */
final class Webhook
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $url,
        private readonly ?string $secret = null,
    ) {
    }

    public function post(array $payload): void
    {
        $payload += ['sent_at' => gmdate('c')];
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];
        if ($this->secret) {
            $headers['X-Lou-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $this->secret);
        }
        $res = $this->http->request('POST', $this->url, $headers, $body);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new LouException("Webhook returned HTTP {$res['status']}");
        }
    }
}
