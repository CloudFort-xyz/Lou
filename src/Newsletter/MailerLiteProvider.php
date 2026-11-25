<?php

declare(strict_types=1);

namespace Lou\Newsletter;

use Lou\Http\HttpClient;
use Lou\LouException;

/**
 * MailerLite (connect.mailerlite.com API): creates or updates a subscriber and adds it to a group.
 * Double opt-in is configured in MailerLite itself (Settings → Subscribe settings).
 */
final class MailerLiteProvider implements Provider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly ?string $groupId = null,
    ) {
    }

    public function subscribe(string $email, array $fields = []): void
    {
        $body = ['email' => $email];
        if (!empty($fields['name'])) {
            $body['fields'] = ['name' => $fields['name']];
        }
        if ($this->groupId) {
            $body['groups'] = [$this->groupId];
        }
        $res = $this->http->request('POST', 'https://connect.mailerlite.com/api/subscribers', [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ], (string) json_encode($body));
        if ($res['status'] >= 300) {
            $detail = json_decode($res['body'], true)['message'] ?? $res['body'];
            throw new LouException("MailerLite error {$res['status']}: " . mb_substr((string) $detail, 0, 300));
        }
    }
}
