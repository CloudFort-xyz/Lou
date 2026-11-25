<?php

declare(strict_types=1);

namespace Lou\Forms;

/**
 * Signed timestamp tokens for the minimum-fill-time check.
 * forms.js fetches one when the visitor first interacts with a form.
 */
final class Token
{
    public function __construct(private readonly string $key)
    {
    }

    public function create(string $form, ?int $now = null): string
    {
        $ts = (string) ($now ?? time());
        return $ts . '.' . $this->sign($form, $ts);
    }

    /** Seconds since the token was issued, or null if it is invalid. */
    public function age(string $token, string $form, ?int $now = null): ?int
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }
        if (!hash_equals($this->sign($form, $m[1]), $m[2])) {
            return null;
        }
        return ($now ?? time()) - (int) $m[1];
    }

    private function sign(string $form, string $ts): string
    {
        return hash_hmac('sha256', "form-token|$form|$ts", $this->key);
    }
}
