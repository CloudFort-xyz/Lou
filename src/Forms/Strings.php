<?php

declare(strict_types=1);

namespace Lou\Forms;

use Lou\App;
use Lou\Support\Fs;

/** UI strings for the endpoint, from site/content/strings/<lang>.json with English fallbacks. */
final class Strings
{
    private const DEFAULTS = [
        'form.success'        => 'Thank you, your message has been sent.',
        'form.error'          => 'Please check the highlighted fields.',
        'form.error.required' => '{field} is required.',
        'form.error.email'    => '{field} must be a valid email address.',
        'form.error.max'      => '{field} must be at most {n} characters.',
        'form.error.min'      => '{field} must be at least {n} characters.',
        'form.error.accepted' => '{field} must be accepted.',
        'form.error.in'       => '{field} has an invalid value.',
        'form.error.phone'    => '{field} must be a valid phone number.',
        'form.error.url'      => '{field} must be a valid URL.',
        'form.error.captcha'  => 'The spam check failed, please try again.',
        'form.error.rate'     => 'Too many submissions, please try again later.',
        'form.error.server'   => 'Something went wrong, please try again later.',
        'form.back'           => 'Back',
    ];

    /** @var array<string, string> */
    private array $strings;

    public function __construct(App $app, public readonly string $lang)
    {
        $file = $app->siteDir . "/content/strings/$lang.json";
        $this->strings = (is_file($file) ? Fs::readJson($file) : []) + self::DEFAULTS;
    }

    public function get(string $key, array $replace = []): string
    {
        $s = (string) ($this->strings[$key] ?? $key);
        foreach ($replace as $k => $v) {
            $s = str_replace('{' . $k . '}', (string) $v, $s);
        }
        return $s;
    }

    public function has(string $key): bool
    {
        return isset($this->strings[$key]);
    }
}
