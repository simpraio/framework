<?php

declare(strict_types=1);

namespace extensions\mail\transport;

use extensions\mail\Envelope;
use extensions\mail\MailTransport;
use RuntimeException;

final class Native implements MailTransport
{
    public function deliver(Envelope $envelope): void
    {
        $error = null;

        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });

        try {
            $sent = mail(
                $envelope->recipientsLine(),
                $envelope->subject,
                $envelope->body,
                $envelope->headersBlock(),
            );
        } finally {
            restore_error_handler();
        }

        if (!$sent || $error !== null) {
            throw new RuntimeException('MAIL_SEND_FAILED: ' . ($error ?? 'mail() returned false'));
        }
    }
}
