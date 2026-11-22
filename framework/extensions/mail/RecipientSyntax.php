<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/**
 * Split only on unquoted commas; reject ambiguous joins rather than silently losing recipients.
 * Quoted display names can legitimately contain addresses, so not every missing comma is detectable.
 * Pass untrusted recipients as an array of bare addresses, never interpolate names into a list string.
 * String scanning avoids PCRE backtracking limits and JIT-dependent acceptance.
 *
 * @internal
 */
final class RecipientSyntax
{
    private const int MAX_BYTES = 1_048_576;

    /**
     * @param bool $split whether a comma outside quotes separates items; an array item is one recipient
     * @return non-empty-list<string>
     */
    public static function items(string $value, bool $split): array
    {
        if (strlen($value) > self::MAX_BYTES) {
            throw new InvalidArgumentException('INVALID_RECIPIENT');
        }
        $items = [];
        $start = 0;
        do {
            [$end, $outside, $quoted] = self::scanItem($value, $start, $split);
            $items[] = self::checked(substr($value, $start, $end - $start), $outside, $quoted, $split);
            $start = $end + 1;
        } while ($end < strlen($value));

        return $items;
    }

    /**
     * @return array{int, string, string} where the item ends - its comma, or the end of $value - the
     *         item outside its quoted strings, each standing as one ", and their contents
     */
    private static function scanItem(string $value, int $start, bool $split): array
    {
        $outside = '';
        $quoted = '';
        $inQuote = false;
        for ($i = $start, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if ($inQuote && $char === '\\' && $i + 1 < $length) {
                $quoted .= $char . $value[++$i];
            } elseif ($char === '"') {
                $outside .= $inQuote ? '' : '"';
                $inQuote = !$inQuote;
            } elseif ($inQuote) {
                $quoted .= $char;
            } elseif ($split && $char === ',') {
                return [$i, $outside, $quoted];
            } else {
                $outside .= $char;
            }
        }
        if ($inQuote) {
            throw new InvalidArgumentException('INVALID_RECIPIENT');
        }

        return [$length, $outside, $quoted];
    }

    private static function checked(string $item, string $outside, string $quoted, bool $split): string
    {
        $joined = $split && str_contains($quoted, ',') && str_contains($quoted, '@');
        if ($joined || self::isRunOn($outside)) {
            throw new InvalidArgumentException('INVALID_RECIPIENT');
        }

        return $item;
    }

    /**
     * Searched with plain string functions: a backtracking pattern is quadratic in the number of @
     * when PCRE runs without JIT.
     *
     * @param string $outside the item outside its quoted strings, each of which stands as one "
     * @return bool whether a comma is left in an array item, a quote follows an @, or a < follows an @ or another <
     */
    private static function isRunOn(string $outside): bool
    {
        $at = strpos($outside, needle: '@');
        $addressAt = strcspn($outside, characters: '@<');

        return str_contains($outside, ',')
            || ($at !== false && strpos($outside, needle: '"', offset: $at + 1) !== false)
            || ($addressAt < strlen($outside) && strpos($outside, needle: '<', offset: $addressAt + 1) !== false);
    }
}
