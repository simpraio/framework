<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/** What one message may cost, decided from lengths alone before anything is encoded. */
final class Limits
{
    public static function assertWithin(Message $message, Config $config): void
    {
        if (count($message->recipients) > $config->maxRecipients) {
            throw new InvalidArgumentException('TOO_MANY_RECIPIENTS');
        }

        // Every attachment part needs more than 128 bytes of framing, even with no data: a count that
        // cannot fit is refused before the list is walked.
        if (count($message->attachments) > intdiv($config->maxMessageBytes, num2: 128)) {
            throw new InvalidArgumentException('MESSAGE_TOO_LARGE');
        }

        AttachmentRules::assertAll($message->attachments);
        if (self::encodedBytes($message) > $config->maxMessageBytes) {
            throw new InvalidArgumentException('MESSAGE_TOO_LARGE');
        }
    }

    /** The text, HTML and attachments the message will carry, at the size Composer encodes them to. */
    private static function encodedBytes(Message $message): int
    {
        $parts = match (true) {
            $message->html === '' => [$message->text],
            $message->text === '' => [$message->html],
            default => [$message->text, $message->html],
        };
        $bytes = 0;

        foreach ($parts as $part) {
            // Every line break leaves as CRLF, a lone CR or LF gaining a byte.
            $bytes += self::base64Bytes(
                strlen($part)
                + substr_count($part, needle: "\n")
                + substr_count($part, needle: "\r")
                - 2 * substr_count($part, needle: "\r\n"),
            );
        }

        foreach ($message->attachments as $attachment) {
            $bytes += self::base64Bytes(strlen($attachment['data']));
        }

        return $bytes;
    }

    /** What chunk_split() makes of $bytes bytes of base64: 76-character lines, each ended by CRLF. */
    private static function base64Bytes(int $bytes): int
    {
        $encoded = intdiv($bytes + 2, num2: 3) * 4;

        return $encoded + max(1, intdiv($encoded + 75, num2: 76)) * 2;
    }
}
