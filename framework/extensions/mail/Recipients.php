<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/**
 * Both transports write these addresses directly; validation prevents command/header injection.
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
