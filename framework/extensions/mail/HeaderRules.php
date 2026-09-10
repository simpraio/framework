<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/**
 * What a header may contain, in one place.
 *
 * {@see Header} renders and encodes; this decides what is admissible in the first place. Both
 * {@see Envelope} and {@see Composer} need that judgement but act on it differently - the envelope
 * refuses, because it is the last point before a transport writes the bytes out, while the composer
 * drops a caller's custom header and carries on.
 */
final class HeaderRules
{
    /** RFC 5322 ftext: the characters a field name may be built from. */
    private const string FIELD_NAME = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /**
     * The bytes a header line may carry: printable ASCII, plus tab as legal whitespace.
     *
     * ASCII rather than UTF-8 because a message with raw UTF-8 header fields needs SMTPUTF8
     * (RFC 6532), which the sender must request in MAIL FROM after the server advertises it
     * (RFC 6531). This client negotiates none of that, so a Unicode header would go out
     * unannounced. Unicode reaches the wire encoded instead - RFC 2047 for the subject and
     * display names, RFC 2231 for attachment filenames - which is ASCII on the wire.
     *
     * Control characters and malformed UTF-8 fail this by construction, so neither needs its own
     * check.
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
     * Whether a header can be serialized safely.
     *
     * The value may already be rendered and legitimately folded, so a CRLF is not forbidden
     * outright - it must begin a continuation line, which is what separates folding from injection.
     * A caller-supplied value is not folded, so {@see Composer} additionally forbids CRLF outright.
     *
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

    /**
     * The same rule, as a refusal.
     *
     * {@see Envelope} is the last point before a transport writes the bytes out - both write them
     * directly and `mail()` checks none of them - so there it is an error rather than something to
     * drop and carry on from.
     */
    public static function assertRenderable(string $name, string $value): void
    {
        if (!self::isRenderable($name, $value)) {
            throw new InvalidArgumentException('INVALID_ENVELOPE_HEADER');
        }
    }
}
