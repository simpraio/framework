<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/**
 * An attachment's name, percent-encoded name and media type are written into its part headers. attach() cleans
 * them, but Message::$attachments is public, so an entry is held to what attach() can produce when the message
 * is built: written directly, a value cannot end a header line or start another.
 */
final class AttachmentRules
{
    /** The longest name attach() takes, in bytes: what most file systems allow. */
    public const int MAX_FILENAME_BYTES = 255;

    /** @param array<array-key, mixed> $attachments */
    public static function assertAll(array $attachments): void
    {
        /** @var mixed $attachment */
        foreach ($attachments as $attachment) {
            if (!self::isValid($attachment)) {
                throw new InvalidArgumentException('INVALID_ATTACHMENT');
            }
        }
    }

    private static function isValid(mixed $attachment): bool
    {
        if (!is_array($attachment)) {
            return false;
        }

        $filename = self::string($attachment['filename'] ?? null);
        $encoded = self::string($attachment['encodedFilename'] ?? null);
        $mimeType = self::string($attachment['mimeType'] ?? null);

        return is_string($attachment['data'] ?? null)
            && $filename !== null
            && strlen($filename) <= self::MAX_FILENAME_BYTES
            && preg_match('/^[\x20\x21\x23-\x3A\x3C-\x5B\x5D-\x7E]*$/D', $filename) === 1
            && $encoded !== null
            && strlen($encoded) <= self::MAX_FILENAME_BYTES * 3
            && preg_match('/^(?:[A-Za-z0-9._~-]|%[0-9A-F]{2})*$/D', $encoded) === 1
            && $mimeType !== null
            && strlen($mimeType) <= 255
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $mimeType) === 1;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
