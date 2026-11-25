<?php

declare(strict_types=1);

namespace Lou\Captcha;

interface Captcha
{
    /** Name of the POST field the widget fills in. */
    public function field(): string;

    public function verify(string $token, string $ip): bool;
}
