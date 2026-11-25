<?php

declare(strict_types=1);

namespace Lou\Newsletter;

use Lou\Http\Webhook;

/** Generic newsletter integration: POSTs the sign-up as JSON (Integrately, IFTTT, Zapier, Make…). */
final class WebhookProvider implements Provider
{
    public function __construct(private readonly Webhook $webhook)
    {
    }

    public function subscribe(string $email, array $fields = []): void
    {
        $this->webhook->post(['event' => 'subscribe', 'email' => $email, 'fields' => $fields]);
    }
}
