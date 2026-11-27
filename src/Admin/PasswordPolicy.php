<?php

declare(strict_types=1);

namespace Lou\Admin;

/**
 * Rejects passwords that are easy to guess, following NIST SP 800-63B: a minimum length (in Users), no composition
 * rules or forced expiry, but a check against commonly used passwords. Only passwords of 10+ characters are listed,
 * because shorter ones are refused anyway.
 */
final class PasswordPolicy
{
    private const COMMON = [
        '1234567890', '0123456789', '0987654321', '1234567891', '12345678910', '123456789a', 'a123456789',
        '1111111111', '0000000000', '1q2w3e4r5t', '1q2w3e4r5t6y', 'q1w2e3r4t5', 'qwertyuiop', 'qwertzuiop',
        'qwerty1234', 'qwerty12345', 'qwerty123456', 'asdfghjkl1', 'asdfghjkl;', '1qaz2wsx3edc', 'zaq12wsxcde3',
        'zxcvbnm123', 'password12', 'password123', 'password1234', 'password1!', 'password!!', 'passw0rd123',
        'p@ssw0rd123', 'p@ssword123', 'iloveyou12', 'iloveyou123', 'letmein123', 'welcome123', 'welcome1234',
        'admin12345', 'admin123456', 'administrator', 'administrator1', 'changeme123', 'football123', 'baseball123',
        'superman123', 'princess123', 'sunshine123', 'starwars123', 'dragon1234', 'monkey1234', 'master1234',
        'abcdefghij', 'abcdefg123', 'abc1234567', 'abcd123456', 'aa12345678', 'qazwsxedcrfv', 'trustno123',
        'jelszo1234', 'jelszo12345', 'jelszo123456', 'jelszavam1', 'jelszavam123', 'titkos1234', 'titkos12345',
        'budapest123', 'hungary123', 'magyarorszag', 'szeretlek12', 'szeretlek123',
    ];

    /** True for a password on the list, one made of a single repeated character, or one that contains the user name. */
    public static function isCommon(string $password, string $name = ''): bool
    {
        $p = mb_strtolower($password);
        if (in_array($p, self::COMMON, true) || preg_match('/^(.)\1*$/su', $p)) {
            return true;
        }
        // "bence12345" for user "bence": the name is the first thing anyone tries
        return mb_strlen($name) >= 3 && str_contains($p, mb_strtolower($name)) && mb_strlen($p) - mb_strlen($name) < 8;
    }
}
