<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\Support\Html;

/**
 * Admin UI translations, gettext style: the English text is the key, so English needs no file
 * and a missing translation shows the English text instead of breaking.
 *
 * Translations live in src/Admin/lang/<code>.php and return ['English text' => 'translation'].
 * Placeholders: {name} is replaced with an escaped value, {!name} with a value that is already
 * HTML (such as a <time> element). "One|Many" picks a form by {n} (n === 1 → first form);
 * a translation without "|" (Hungarian nouns don't take a plural after a number) is used as is.
 * The message text itself comes from Lou's own files and may contain simple markup (<code>, <strong>).
 */
final class I18n
{
    /** Admin UI languages, by code, in their own language. */
    public const LANGUAGES = ['en' => 'English', 'hu' => 'Magyar'];

    /** Strings admin.js needs; they are handed to it in <body data-i18n>. */
    public const JS = [
        'Working…', 'Copied', 'Press Ctrl+C', 'Saved to your draft.', 'Saved.', 'Saving failed', 'Saving…',
        'Server error ({status})', 'Uploading…', 'Edit: {title}', 'Creating the link…',
        'Live viewer link, valid for a day', 'Preview link, valid for {days} days', '{text}, copied to the clipboard.', 'Failed',
    ];

    /** @param array<string, string> $messages */
    private function __construct(public readonly string $lang, private readonly array $messages)
    {
    }

    public static function load(string $lang): self
    {
        if (!isset(self::LANGUAGES[$lang])) {
            $lang = 'en';
        }
        $file = __DIR__ . "/lang/$lang.php";
        return new self($lang, $lang !== 'en' && is_file($file) ? (array) require $file : []);
    }

    /** The best admin language for an Accept-Language header, or null if none fits. */
    public static function negotiate(string $header): ?string
    {
        $best = null;
        $bestQ = 0.0;
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $code = strtolower(substr(trim($bits[0]), 0, 2));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $b) {
                if (preg_match('/^\s*q=([0-9.]+)\s*$/', $b, $m)) {
                    $q = (float) $m[1];
                }
            }
            if (isset(self::LANGUAGES[$code]) && $q > $bestQ) {
                [$best, $bestQ] = [$code, $q];
            }
        }
        return $best;
    }

    /** Translated, as HTML: {name} values are escaped, {!name} values are inserted as they are. */
    public function __invoke(string $message, array $vars = []): string
    {
        return $this->render($message, $vars, true);
    }

    /** Translated, as plain text (for flash messages, exceptions, JSON): escape it where it is output. */
    public function plain(string $message, array $vars = []): string
    {
        return $this->render($message, $vars, false);
    }

    /** True when this language has its own text for the message (English always does). */
    public function has(string $message): bool
    {
        return $this->lang === 'en' || isset($this->messages[$message]);
    }

    /** @return array<string, string> the translations admin.js needs (empty for English) */
    public function js(): array
    {
        $out = [];
        foreach (self::JS as $key) {
            if (isset($this->messages[$key])) {
                $out[$key] = $this->messages[$key];
            }
        }
        return $out;
    }

    private function render(string $message, array $vars, bool $html): string
    {
        $text = $this->messages[$message] ?? $message;
        if (str_contains($text, '|') && array_key_exists('n', $vars)) {
            $forms = explode('|', $text, 2);
            $text = (int) $vars['n'] === 1 ? $forms[0] : $forms[1];
        }
        return (string) preg_replace_callback('/\{(!?)([a-z_]+)\}/', static function (array $m) use ($vars, $html): string {
            if (!array_key_exists($m[2], $vars)) {
                return $m[0];
            }
            $value = (string) $vars[$m[2]];
            return $html && $m[1] === '' ? Html::escape($value) : $value;
        }, $text);
    }
}
