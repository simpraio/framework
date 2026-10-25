<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

final class HeaderRules
{
    /** RFC 5322 ftext: the characters a field name may be built from. */
    private const string FIELD_NAME = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /**
     * ASCII only: SMTPUTF8 is not negotiated. Unicode must use RFC 2047/2231 encoding.
     */
    private const string WIRE_SAFE = '/^[\x09\x20-\x7E]*$/D';

    public static function nameIsValid(string $name): bool
    {
        return preg_match(self::FIELD_NAME, $name) === 1;
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
