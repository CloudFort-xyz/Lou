<?php

declare(strict_types=1);

namespace Lou\Mail;

interface Mailer
{
    /** @throws \Lou\LouException when sending fails */
    public function send(Message $message): void;
}
