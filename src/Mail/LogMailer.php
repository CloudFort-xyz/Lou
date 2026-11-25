<?php

declare(strict_types=1);

namespace Lou\Mail;

use Lou\Support\Fs;

/** Development mailer: appends messages to a log file instead of sending them. */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $file)
    {
    }

    public function send(Message $message): void
    {
        Fs::ensureDir(dirname($this->file));
        file_put_contents($this->file, sprintf(
            "--- %s\nTo: %s\nReply-To: %s\nSubject: %s\n\n%s\n\n",
            gmdate('c'), implode(', ', $message->to), $message->replyTo ?? '-', $message->subject, $message->text,
        ), FILE_APPEND | LOCK_EX);
    }
}
