<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/**
 * The envelope's recipient list: validated, and rendered as a `To:` value.
 *
 * Separate from {@see Envelope} because it answers a question about the addresses themselves rather
 * than about the message. Both transports depend on this validation - the SMTP client puts each
 * address in a RCPT TO command, and `mail()` writes the rendered value into a To header without
 * checking it - so an address that got here unvalidated would reach the wire either way.
 */
final class Recipients
{
    /**
     * @param list<string> $recipients
     * @return non-empty-list<string>
     */
    public static function normalize(array $recipients): array
    {
        if ($recipients === []) {
            throw new InvalidArgumentException('NO_RECIPIENT');
        }

        $normalized = [];

        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);

            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('INVALID_ENVELOPE_RECIPIENT');
            }

            $normalized[] = $recipient;
        }

        return $normalized;
    }

    /**
     * The addresses as a `To:` header value, folded between addresses so no line passes
     * Header::MAX_LINE_BYTES.
     *
     * @param non-empty-list<string> $recipients
     */
    public static function fold(array $recipients): string
    {
        $lines = [];
        $line = $recipients[0];

        foreach (array_slice(array: $recipients, offset: 1) as $recipient) {
            $addition = ', ' . $recipient;
            $prefixBytes = $lines === [] ? strlen('To: ') : 0;

            // Keep one byte available for the comma needed when another address starts a new line.
            if (strlen($line . $addition) + $prefixBytes < Header::MAX_LINE_BYTES) {
                $line .= $addition;
                continue;
            }

            $lines[] = $line . ',';
            $line = ' ' . $recipient;
        }

        $lines[] = $line;

        return implode("\r\n", $lines);
    }
}
