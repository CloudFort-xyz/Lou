<?php

declare(strict_types=1);

namespace Lou\Forms;

/**
 * Validates POSTed values against rule strings like "required|email|max:100".
 * Rules: required, email, max:N, min:N, accepted, in:a,b,c, phone, url.
 */
final class Validator
{
    /**
     * @param array<string, string> $rules  field => rules
     * @param array<string, mixed>  $input
     * @return array{0: array<string, string>, 1: array<string, array{0: string, 1: array}>} [clean data, errors field => [key, params]]
     */
    public static function validate(array $rules, array $input): array
    {
        $data = [];
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $raw = $input[$field] ?? '';
            $value = is_string($raw) ? self::clean($raw) : '';
            $list = array_filter(explode('|', $ruleString));
            $required = in_array('required', $list, true) || in_array('accepted', $list, true);

            if ($value === '') {
                if ($required) {
                    $errors[$field] = [in_array('accepted', $list, true) ? 'form.error.accepted' : 'form.error.required', []];
                }
                $data[$field] = '';
                continue;
            }
            foreach ($list as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                $error = match ($name) {
                    'required' => null,
                    'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $value) ? ['form.error.email', []] : null,
                    'max'      => mb_strlen($value) > (int) $arg ? ['form.error.max', ['n' => (int) $arg]] : null,
                    'min'      => mb_strlen($value) < (int) $arg ? ['form.error.min', ['n' => (int) $arg]] : null,
                    'accepted' => in_array(strtolower($value), ['1', 'on', 'yes', 'true'], true) ? null : ['form.error.accepted', []],
                    'in'       => in_array($value, explode(',', $arg), true) ? null : ['form.error.in', []],
                    'phone'    => preg_match('/^\+?[0-9 ()\-\/.]{5,30}$/', $value) ? null : ['form.error.phone', []],
                    'url'      => filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value) ? null : ['form.error.url', []],
                    default    => throw new \Lou\LouException("Unknown validation rule '$name' for field '$field'"),
                };
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
            $data[$field] = $value;
        }
        // hard cap on any single value to keep the database sane, even without a max rule
        foreach ($data as $f => $v) {
            if (mb_strlen($v) > 20000 && !isset($errors[$f])) {
                $errors[$f] = ['form.error.max', ['n' => 20000]];
            }
        }
        return [$data, $errors];
    }

    private static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return trim($s);
    }
}
