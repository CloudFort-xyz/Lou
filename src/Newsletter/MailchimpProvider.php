<?php

declare(strict_types=1);

namespace Lou\Newsletter;

use Lou\Http\HttpClient;
use Lou\LouException;

/**
 * Mailchimp Marketing API v3: upserts a list member.
 * With double opt-in (default) new members are "pending" and get Mailchimp's confirmation email.
 */
final class MailchimpProvider implements Provider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly string $listId,
        private readonly bool $doubleOptIn = true,
        private readonly array $tags = [],
    ) {
    }

    public function subscribe(string $email, array $fields = []): void
    {
        $dc = substr(strrchr($this->apiKey, '-') ?: '', 1);
        if (!preg_match('/^[a-z0-9]+$/', $dc)) {
            throw new LouException('Mailchimp API key must end with the data center, e.g. "-us21"');
        }
        $hash = md5(strtolower(trim($email)));
        $body = [
            'email_address' => $email,
            'status_if_new' => $this->doubleOptIn ? 'pending' : 'subscribed',
        ];
        $merge = [];
        if (!empty($fields['name'])) {
            $parts = preg_split('/\s+/', trim($fields['name']), 2);
            $merge['FNAME'] = $parts[0];
            if (isset($parts[1])) {
                $merge['LNAME'] = $parts[1];
            }
        }
        if ($merge) {
            $body['merge_fields'] = $merge;
        }
        if ($this->tags) {
            $body['tags'] = array_values($this->tags);
        }
        if (!empty($fields['lang'])) {
            $body['language'] = $fields['lang'];
        }
        $res = $this->http->request(
            'PUT',
            "https://$dc.api.mailchimp.com/3.0/lists/" . rawurlencode($this->listId) . "/members/$hash",
            ['Authorization' => 'Basic ' . base64_encode('lou:' . $this->apiKey), 'Content-Type' => 'application/json'],
            (string) json_encode($body),
        );
        if ($res['status'] >= 300) {
            $detail = json_decode($res['body'], true)['detail'] ?? $res['body'];
            throw new LouException("Mailchimp error {$res['status']}: " . mb_substr((string) $detail, 0, 300));
        }
    }
}
