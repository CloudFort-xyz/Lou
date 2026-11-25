<?php

declare(strict_types=1);

namespace Lou\Admin;

use Lou\Support\Html;

/**
 * One admin user's preferences, stored with the user in var/users.json under "prefs".
 * An empty value means "the default": the site's admin language, the browser's time zone,
 * the language's usual date format, the site's default content language.
 */
final class Prefs
{
    /** Date and time formats: [date pattern, time pattern]. admin.js understands the same tokens (Y m d H i g A). */
    public const DATE_FORMATS = [
        'iso' => ['Y-m-d', 'H:i'],
        'hu'  => ['Y. m. d.', 'H:i'],
        'eu'  => ['d.m.Y', 'H:i'],
        'uk'  => ['d/m/Y', 'H:i'],
        'us'  => ['m/d/Y', 'g:i A'],
    ];

    /** The usual format per admin language when the user picks none. */
    private const LANGUAGE_FORMATS = ['en' => 'iso', 'hu' => 'hu'];

    public const START_PAGES = ['dashboard', 'pages', 'review'];

    public function __construct(
        public readonly string $lang = '',
        public readonly string $timezone = '',
        public readonly string $dateFormat = '',
        public readonly string $editLang = '',
        public readonly string $startPage = '',
    ) {
    }

    /** From stored values; anything unknown falls back to the default, so a bad file never breaks the admin. */
    public static function fromArray(array $a): self
    {
        $s = static fn (string $k): string => is_string($a[$k] ?? null) ? $a[$k] : '';
        return new self(
            isset(I18n::LANGUAGES[$s('lang')]) ? $s('lang') : '',
            self::validTimezone($s('timezone')) ? $s('timezone') : '',
            isset(self::DATE_FORMATS[$s('date_format')]) ? $s('date_format') : '',
            preg_match('/^[a-z]{2,3}(-[a-z0-9]+)?$/', $s('edit_lang')) ? $s('edit_lang') : '',
            in_array($s('start_page'), self::START_PAGES, true) ? $s('start_page') : '',
        );
    }

    /** @return array<string, string> only the values that differ from the default */
    public function toArray(): array
    {
        return array_filter([
            'lang' => $this->lang, 'timezone' => $this->timezone, 'date_format' => $this->dateFormat,
            'edit_lang' => $this->editLang, 'start_page' => $this->startPage,
        ], static fn (string $v): bool => $v !== '');
    }

    public static function validTimezone(string $tz): bool
    {
        return $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }

    /** @return array{0: string, 1: string} the [date, time] patterns for this user in the given admin language */
    public function patterns(string $uiLang): array
    {
        return self::DATE_FORMATS[$this->dateFormat !== '' ? $this->dateFormat : (self::LANGUAGE_FORMATS[$uiLang] ?? 'iso')];
    }

    /**
     * A timestamp as <time>. With a time zone set, it is rendered in that zone and admin.js leaves it alone
     * (data-fixed). Without one, it is rendered in UTC and admin.js shows it in the browser's own time zone.
     * The UTC time is always in the tooltip. Takes an ISO date, a SQLite "Y-m-d H:i:s" (UTC) or a Unix time.
     */
    public function stamp(string|int|null $at, bool $dateOnly, string $uiLang): string
    {
        if ($at === null || $at === '') {
            return '';
        }
        try {
            $d = is_int($at) ? new \DateTimeImmutable("@$at") : new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return Html::escape((string) $at);
        }
        $utc = $d->setTimezone(new \DateTimeZone('UTC'));
        [$date, $time] = $this->patterns($uiLang);
        $pattern = $dateOnly ? $date : "$date $time";
        $attrs = ' datetime="' . $utc->format('Y-m-d\\TH:i:s\\Z') . '"' . ($dateOnly ? ' data-date' : '');
        if ($this->timezone !== '') {
            $local = $utc->setTimezone(new \DateTimeZone($this->timezone));
            return '<time' . $attrs . ' data-fixed title="' . $utc->format('Y-m-d H:i') . ' UTC">' . Html::escape($local->format($pattern)) . '</time>';
        }
        return '<time' . $attrs . '>' . Html::escape($utc->format($pattern)) . ($dateOnly ? '' : ' UTC') . '</time>';
    }
}
