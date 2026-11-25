<?php

declare(strict_types=1);

namespace Lou\Captcha;

use Lou\Http\HttpClient;

/** Google reCAPTCHA v3 (invisible, score based), verified server-side. */
final class RecaptchaCaptcha implements Captcha
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $secret,
        private readonly float $minScore = 0.5,
    ) {
    }

    public function field(): string
    {
        return 'g-recaptcha-response';
    }

    public function verify(string $token, string $ip): bool
    {
        if ($token === '' || strlen($token) > 4096) {
            return false;
        }
        $res = $this->http->request('POST', 'https://www.google.com/recaptcha/api/siteverify',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(['secret' => $this->secret, 'response' => $token, 'remoteip' => $ip]));
        $data = json_decode($res['body'], true) ?: [];
        return $res['status'] === 200
            && ($data['success'] ?? false) === true
            && (float) ($data['score'] ?? 0) >= $this->minScore
            && ($data['action'] ?? 'submit') === 'submit';
    }
}
