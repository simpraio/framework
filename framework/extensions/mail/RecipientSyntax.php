<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

/**
 * Splits a recipient list on the commas outside quoted strings; in a quoted string a backslash escapes
 * the next character. The address is taken from the angle-addr that ends an item, so input that would
 * let one item swallow another refuses the whole call:
 * - a quote left open;
 * - a quote opened after an @ outside quotes in the same item: only the domain and a closing > follow
 *   an address's @;
 * - a < after an @ or another < outside quotes in the same item: a comma is missing;
 * - a comma outside quotes in an array item;
 * - in a string, an item whose quoted strings hold a comma and an @ between them: what stray quotes
 *   in display names make of the recipients between them.
 * A missed comma is seen only where the earlier address is outside quotes: with stray quotes in the
 * display names around it, as in a" jan@x.test b" <eva@x.test>, it reads as a display name whose
 * quoted string holds an address - the same form as Help "jan@x.test desk" <eva@x.test>, which no
 * syntax rule can refuse without refusing such names. Unescaped names from users therefore belong in an
 * array of bare addresses, never in a string. Whether what is left of an item is an address is for the
 * address check to decide.
 *
 * Read in one pass with string functions only, so no PCRE limit or JIT setting changes a verdict, and
 * a string or array item over MAX_BYTES is refused before it is read: a list a thousand times longer
 * than any real one would otherwise hold PHP's memory limit hostage.
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
     * Reads one item from $start.
     *
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
     * @return bool whether a comma is left in an array item, a quote follows an @, or a < follows an
     *              @ or another <
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
