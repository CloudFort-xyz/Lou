<?php

declare(strict_types=1);

namespace Lou\Newsletter;

interface Provider
{
    /**
     * @param array<string, string> $fields extra fields (e.g. name)
     * @throws \Lou\LouException on failure
     */
    public function subscribe(string $email, array $fields = []): void;
}
