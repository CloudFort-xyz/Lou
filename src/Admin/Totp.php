<?php

declare(strict_types=1);

namespace Lou\Admin;

/**
 * Time-based one-time passwords (RFC 6238, the codes authenticator apps show): HMAC-SHA1, 6 digits, 30-second steps.
 * Works with Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden, Aegis and others.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Codes from one step before or after are accepted too, for clocks that are a little off. */
    private const WINDOW = 1;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random 160-bit secret, base32-encoded (32 characters), as RFC 4226 recommends. */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 10 ** self::DIGITS;
        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step a code belongs to, or null when it is wrong. Steps at or before $after are refused, so a code
     * can't be used twice (someone looking over your shoulder can't replay it).
     */
    public static function verify(string $secret, string $code, int $now, int $after = -1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $current = intdiv($now, self::PERIOD);
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($step > $after && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** The otpauth:// link authenticator apps read from the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** The secret in groups of four, easier to type into an app by hand. */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[\s=]+/', '', $text) ?? '')) as $c) {
            $v = strpos(self::BASE32, $c);
            if ($v !== false) {
                $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }
}
