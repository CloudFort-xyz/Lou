<?php

declare(strict_types=1);

namespace Lou\Mail;

use Lou\LouException;

/**
 * Validates the email settings form (Admin → Settings → Email, and the installer).
 */
final class MailSettings
{
    /**
     * @param \Closure(string): string $input   reads one posted field
     * @param array<string, mixed>     $current the stored SMTP settings (an empty password keeps the stored one)
     * @return array{mail: array{transport: string, from: string, from_name: string}, smtp: ?array<string, mixed>}
     *         smtp is null when no SMTP server was entered (and none is needed)
     * @throws LouException
     */
    public static function fromInput(\Closure $input, array $current = []): array
    {
        $transport = $input('transport');
        if (!in_array($transport, ['smtp', 'log'], true)) {
            throw new LouException('Choose how emails are sent');
        }
        $from = trim($input('from'));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new LouException('The sender address must be a valid email address');
        }
        $fromName = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $input('from_name')));
        if (mb_strlen($fromName) > 100) {
            throw new LouException('The sender name is too long (100 characters at most)');
        }

        $smtp = null;
        $host = strtolower(trim($input('host')));
        if ($host !== '' || $transport === 'smtp') {
            if (!preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
                throw new LouException('The SMTP server must be a host name like mail.example.hu');
            }
            $port = (int) $input('port');
            if ($port < 1 || $port > 65535) {
                throw new LouException('The SMTP port must be a number between 1 and 65535');
            }
            $secure = $input('secure');
            if (!in_array($secure, ['tls', 'ssl', ''], true)) {
                throw new LouException('Unknown encryption setting');
            }
            $user = trim($input('user'));
            if (mb_strlen($user) > 200 || preg_match('/[\x00-\x1F\x7F]/', $user)) {
                throw new LouException('The SMTP user name is not valid');
            }
            $pass = $input('pass');
            if ($pass === '' && $input('clear_pass') !== '1') {
                $pass = (string) ($current['pass'] ?? '');
            }
            if (strlen($pass) > 500) {
                throw new LouException('The SMTP password is too long');
            }
            $smtp = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'secure' => $secure];
        }
        return ['mail' => ['transport' => $transport, 'from' => $from, 'from_name' => $fromName], 'smtp' => $smtp];
    }
}
