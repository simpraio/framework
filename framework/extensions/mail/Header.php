<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;
use RuntimeException;

final class Header
{
    /** RFC 5322 2.1.1: a line carries at most 998 octets before its CRLF. */
    public const int MAX_LINE_BYTES = 998;

    /** RFC 2047 encoded-words are limited to 75 characters; 45 input bytes encode to 60. */
    private const int ENCODED_WORD_INPUT_BYTES = 45;

    private const int PARAMETER_SEGMENT_BYTES = 60;

    // Budget reserved for the header name and its ": " delimiter when deciding whether a value
    // fits unencoded. 'Subject: ' is the longest name encode() is used for; a caller adding a
    // longer one must keep this in step or an over-long line can slip through unencoded.
    private const int NAME_BUDGET_BYTES = 9;
    private const string EOL = "\r\n";

    public static function from(string $name, string $email): string
    {
        // A display name is free text, so a line break in it is flattened to a space rather than
        // refused; encode() below then sees a value that cannot carry one.
        $name = (string)preg_replace(pattern: '/[\r\n]+/', replacement: ' ', subject: trim($name));

        if ($name === '') {
            return $email;
        }

        $rendered = preg_match('/^[!#$%&\'*+\-\/=\?^_`{|}~0-9A-Za-z]+(?: [!#$%&\'*+\-\/=\?^_`{|}~0-9A-Za-z]+)*$/D', $name) === 1
            ? $name
            : '"' . addcslashes(string: $name, characters: '\\"') . '"';

        if (
            preg_match('/^[\x20-\x7E]+$/D', $rendered) !== 1
            || strlen('From: ' . $rendered . ' <' . $email . '>') > self::MAX_LINE_BYTES
        ) {
            $rendered = self::encode($name, force: true);
        }

        return $rendered . ' <' . $email . '>';
    }

    public static function attachment(
        string $base,
        string $attribute,
        string $filename,
        string $encodedFilename,
    ): string {
        if (rawurlencode($filename) === $encodedFilename) {
            return $base . ';' . self::EOL . ' ' . $attribute . '="' . $filename . '"';
        }

        $parameters = [];
        $segments = self::parameterSegments($encodedFilename);

        if (count($segments) === 1) {
            $parameters[] = ' ' . $attribute . "*=UTF-8''" . $segments[0];
        } else {
            foreach ($segments as $index => $segment) {
                $parameters[] = sprintf(
                    ' %s*%d*=%s%s',
                    $attribute,
                    $index,
                    $index === 0 ? "UTF-8''" : '',
                    $segment,
                );
            }
        }

        return $base . ';' . self::EOL . implode(';' . self::EOL, $parameters);
    }

    public static function encode(string $value, bool $force = false): string
    {
        // CR and LF are refused rather than flattened. A value reaching here is not yet folded -
        // this method decides the folding - so a line break in it is either a caller mistake or an
        // injection attempt, and silently rewriting it would hide both.
        $normalized = $value;

        if (!function_exists('mb_check_encoding') || !function_exists('mb_strcut')) {
            throw new RuntimeException('MAIL_MBSTRING_REQUIRED');
        }

        if (
            preg_match('/[\x00-\x08\x0A-\x0D\x0E-\x1F\x7F]/', $normalized) === 1
            || !mb_check_encoding(value: $normalized, encoding: 'UTF-8')
        ) {
            throw new InvalidArgumentException('INVALID_MAIL_HEADER_VALUE');
        }

        if (
            !$force
            && preg_match('/^[\x20-\x7E]*$/D', $normalized) === 1
            && self::NAME_BUDGET_BYTES + strlen($normalized) <= self::MAX_LINE_BYTES
        ) {
            return $normalized;
        }

        $words = [];

        // Terminates because the value is verified UTF-8 above and no character exceeds four
        // bytes, so mb_strcut always returns at least one character of the 45-byte window.
        while ($normalized !== '') {
            $chunk = mb_strcut(
                string: $normalized,
                start: 0,
                length: self::ENCODED_WORD_INPUT_BYTES,
                encoding: 'UTF-8',
            );
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $normalized = substr($normalized, strlen($chunk));
        }

        return implode(self::EOL . ' ', $words);
    }

    /** @return non-empty-list<string> */
    private static function parameterSegments(string $encoded): array
    {
        $segments = [];
        $segment = '';
        $length = strlen($encoded);

        for ($offset = 0; $offset < $length;) {
            $tokenLength = $encoded[$offset] === '%' && $offset + 2 < $length ? 3 : 1;
            $token = substr(string: $encoded, offset: $offset, length: $tokenLength);

            if ($segment !== '' && strlen($segment . $token) > self::PARAMETER_SEGMENT_BYTES) {
                $segments[] = $segment;
                $segment = '';
            }

            $segment .= $token;
            $offset += $tokenLength;
        }

        $segments[] = $segment;

        return $segments;
    }
}
