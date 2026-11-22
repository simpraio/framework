<?php

declare(strict_types=1);

namespace core\log;

final class Text
{
    private const int MAX_BYTES = 65_536;

    /**
     * Escape line, terminal and direction controls to prevent forged or misleading log entries.
     * Preserve encoded context as valid JSON; its C0 controls are already escaped by json_encode().
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
