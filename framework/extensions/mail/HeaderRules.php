<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

final class HeaderRules
{
    /**
     * RFC 5322 ftext: the characters a field name may be built from. A leading dot is refused:
     * any SMTP hop that skips dot-stuffing turns `.From` into `From`. Digit-only names are refused too.
     */
    private const string FIELD_NAME = '/^(?![0-9]+$)[!#$%&\'*+\-^_`|~0-9A-Za-z][!#$%&\'*+\-.^_`|~0-9A-Za-z]*$/D';

    /**
     * ASCII only: SMTPUTF8 is not negotiated. Unicode must use RFC 2047/2231 encoding.
     */
    private const string WIRE_SAFE = '/^[\x09\x20-\x7E]*$/D';

    private const string ATOM = "[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+";
    private const string LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';

    public static function nameIsValid(string $name): bool
    {
        // Canonical integers, including negatives, become integer keys in a PHP header map.
        return preg_match(self::FIELD_NAME, $name) === 1 && (string)(int)$name !== $name;
    }

    /**
     * @param string $messageId the id without its angle brackets: a dot-atom, an @ and a host name. A colon is
     *                          not an atext character, so an id built from a key holding one is refused.
     */
    private static function messageIdIsValid(string $messageId): bool
    {
        return strlen($messageId) <= 255
            && preg_match(
                '/^' . self::ATOM . '(?:\.' . self::ATOM . ')*@' . self::LABEL . '(?:\.' . self::LABEL . ')*$/D',
                $messageId,
            ) === 1;
    }

    public static function assertMessageId(string $messageId): void
    {
        if (!self::messageIdIsValid($messageId)) {
            throw new InvalidArgumentException('INVALID_MESSAGE_ID');
        }
    }

    public static function assertName(string $name): void
    {
        if (!self::nameIsValid($name)) {
            throw new InvalidArgumentException('INVALID_ENVELOPE_HEADER_NAME');
        }
    }

    /**
     * Accept rendered folding only when CRLF starts a continuation line.
     */
    public static function isRenderable(string $name, string $value): bool
    {
        foreach (explode("\r\n", $name . ': ' . $value) as $index => $line) {
            $folded = $index === 0 || str_starts_with($line, ' ') || str_starts_with($line, "\t");

            // A CR or LF surviving the split formed no pair, so it cannot be folding.
            if (
                !$folded
                || strlen($line) > Header::MAX_LINE_BYTES
                || preg_match(self::WIRE_SAFE, $line) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    public static function assertRenderable(string $name, string $value): void
    {
        if (!self::isRenderable($name, $value)) {
            throw new InvalidArgumentException('INVALID_ENVELOPE_HEADER');
        }
    }
}
