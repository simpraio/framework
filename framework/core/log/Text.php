<?php

declare(strict_types=1);

namespace core\log;

/**
 * Keeps logged text on one line, showing what was logged, and bounded. Exception messages and
 * context values carry request input, so a log line is attacker-influenced text.
 */
final class Text
{
    private const int MAX_BYTES = 65_536;

    /**
     * Escapes C0 and C1 controls, DEL, U+2028 and U+2029, and the bidirectional controls and byte order
     * mark that make a terminal or log viewer show other text than was logged; invalid UTF-8 becomes
     * '?' first. Every escape other than \0 is also a valid JSON escape, and json_encode() already
     * escapes every C0 control, so an encoded context stays JSON that decodes to what was logged.
     */
    public static function escape(string $text): string
    {
        if (preg_match('/\A[\x20-\x7e]*\z/', $text) === 1) {
            return $text;
        }

        return (string)preg_replace_callback(
            '/[\x00-\x1f\x7f\x{80}-\x{9f}\x{061C}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
            static fn(array $match): string => match ($match[0]) {
                "\r" => '\r',
                "\n" => '\n',
                "\t" => '\t',
                "\0" => '\0',
                default => sprintf('\u%04X', mb_ord($match[0], encoding: 'UTF-8')),
            },
            mb_scrub($text, encoding: 'UTF-8'),
        );
    }

    /** Cut at MAX_BYTES on a character boundary, saying how much was dropped. */
    public static function cap(string $text): string
    {
        if (strlen($text) <= self::MAX_BYTES) {
            return $text;
        }

        $kept = mb_strcut($text, start: 0, length: self::MAX_BYTES, encoding: 'UTF-8');

        return $kept . '...[truncated ' . (strlen($text) - strlen($kept)) . ' bytes]';
    }
}
