<?php

declare(strict_types=1);

namespace Lou\Mail;

use Lou\LouException;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP via the bundled PHPMailer (vendor-bundled/phpmailer).
 *
 * @phpstan-type Smtp array{host: string, port?: int, user?: string, pass?: string, secure?: 'tls'|'ssl'|''}
 */
final class PhpMailerMailer implements Mailer
{
    /** @param array<string, mixed> $smtp */
    public function __construct(
        private readonly array $smtp,
        private readonly string $from,
        private readonly string $fromName = '',
    ) {
        $dir = dirname(__DIR__, 2) . '/vendor-bundled/phpmailer';
        require_once $dir . '/Exception.php';
        require_once $dir . '/PHPMailer.php';
        require_once $dir . '/SMTP.php';
    }

    /** Build the PHPMailer instance (separate so it can be inspected in tests without sending). */
    public function prepare(Message $message): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->isSMTP();
        $m->Host = (string) ($this->smtp['host'] ?? throw new LouException("Missing 'smtp.host' in .env.php"));
        $m->Port = (int) ($this->smtp['port'] ?? 587);
        $secure = (string) ($this->smtp['secure'] ?? 'tls');
        $m->SMTPSecure = match ($secure) {
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $m->SMTPAutoTLS = $secure !== '';
        if (!empty($this->smtp['user'])) {
            $m->SMTPAuth = true;
            $m->Username = (string) $this->smtp['user'];
            $m->Password = (string) ($this->smtp['pass'] ?? '');
        }
        $m->Timeout = 15;

        $m->setFrom($this->from, $this->fromName);
        foreach ($message->to as $to) {
            $m->addAddress($to);
        }
        if ($message->replyTo !== null) {
            $m->addReplyTo($message->replyTo, $message->replyToName ?? '');
        }
        $m->Subject = $message->subject;
        $m->isHTML(false);
        $m->Body = $message->text;
        return $m;
    }

    public function send(Message $message): void
    {
        try {
            $this->prepare($message)->send();
        } catch (PhpMailerException $e) {
            throw new LouException('Sending email failed: ' . $e->getMessage());
        }
    }
}
