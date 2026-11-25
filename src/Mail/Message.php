<?php

declare(strict_types=1);

namespace Lou\Mail;

final class Message
{
    /** @param list<string> $to */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $replyTo = null,
        public readonly ?string $replyToName = null,
    ) {
    }
}
