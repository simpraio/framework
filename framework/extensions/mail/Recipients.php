<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/**
 * Both transports write these addresses directly into a command or header line, so an address must
 * hold nothing that ends or reshapes one, as well as be valid.
 */
final class Recipients
{
    /**
     * The rule of Validator::email(). FILTER_VALIDATE_EMAIL admits a quoted local part, whose quoted-pairs
     * hold any ASCII byte, a line feed included, and which a relay reading <...> without its quoting takes
     * for a different address: no control byte, space, quote, backslash or angle bracket passes.
     */
    public static function isAddress(string $address): bool
    {
        return preg_match('/^[^\x00-\x20\x7f<>"\\\\]+$/D', $address) === 1 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

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

            if (!self::isAddress($recipient)) {
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
