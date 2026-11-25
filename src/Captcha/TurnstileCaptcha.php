<?php

declare(strict_types=1);

namespace Lou\Captcha;

use Lou\Http\HttpClient;

/** Cloudflare Turnstile, verified server-side. */
final class TurnstileCaptcha implements Captcha
{
    public function __construct(private readonly HttpClient $http, private readonly string $secret)
    {
    }

    public function field(): string
    {
        return 'cf-turnstile-response';
    }

    public function verify(string $token, string $ip): bool
    {
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }
        $res = $this->http->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['secret' => $this->secret, 'response' => $token, 'remoteip' => $ip]));
        return ($res['status'] === 200) && (json_decode($res['body'], true)['success'] ?? false) === true;
    }
}
