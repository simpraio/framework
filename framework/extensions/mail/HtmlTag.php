<?php

declare(strict_types=1);

namespace extensions\mail;

/**
 * Where a tag or a closing tag ends, read by the HTML tokenizer's rules: a quote opens a quoted value only
 * straight after "=", and anywhere else it is an ordinary character. Every scan moves forward only, so no
 * input makes one run twice over the same bytes. Also the address a link's href gives.
 *
 * @internal used by PlainText
 */
final class HtmlTag
{
    public const string SPACE = " \t\n\f\r";

    public const string LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * A carriage return reference as DOMDocument writes it, which html_entity_decode() leaves as written. Replace
     * it with a character, never with nothing, or its neighbours join into another reference, as `&num&#13;;` would.
     * No pattern: a regular expression returns nothing once pcre.backtrack_limit runs out.
     */
    public const array CR_REFERENCES = ['&#13;', '&#xd;'];

    /**
     * The address of a link to a web or mail address, null for any other: decoded and trimmed as a browser reads
     * it, with controls, spaces, quotes, angle brackets and non-ASCII percent-encoded, so nothing in it can pose
     * as a second address.
     */
    public static function address(?string $href): ?string
    {
        // A carriage return reference is a CR, removed below with the others, as a browser's URL parser does.
        $url = str_ireplace(self::CR_REFERENCES, replace: "\r", subject: $href ?? '');
        $url = trim(html_entity_decode($url, flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'), characters: "\x00..\x20");
        $url = str_replace(["\t", "\n", "\r"], replace: '', subject: $url);
        $scheme = strtolower(substr($url, offset: 0, length: strcspn($url, characters: ':') + 1));
        // "&#" left after decoding is a reference PHP would not decode, such as &#1;, which a browser reads as a
        // control: shown as written, its "#" would make the host after it read as a fragment.
        if (!in_array($scheme, ['http:', 'https:', 'mailto:'], strict: true) || str_contains($url, '&#')) {
            return null;
        }

        // Parentheses too: the address is shown inside them, so one in it could close it and open another.
        static $encoding = null;
        if ($encoding === null) {
            $unsafe = array_map(chr(...), [...range(0, end: 0x20), 0x22, 0x28, 0x29, 0x3C, 0x3E, 0x60, ...range(0x7F, end: 0xFF)]);
            $encoding = array_combine($unsafe, array_map(static fn(string $byte): string => sprintf('%%%02X', ord($byte)), $unsafe));
        }

        return strtr($url, $encoding);
    }

    /**
     * The > that ends a tag whose name ends at $at, or false when the markup ends first. $href is the first
     * href attribute's value as written, '' for one without a value; a later one is ignored, as in a browser.
     */
    public static function end(string $html, int $at, ?string &$href = null): int|false
    {
        $href = null;

        while (true) {
            $at += strspn($html, self::SPACE . '/', $at);
            $char = substr($html, $at, length: 1);
            if ($char === '') {
                return false;
            }
            if ($char === '>') {
                return $at;
            }

            // An attribute name runs to a space, /, > or =; a leading = belongs to the name.
            $nameStart = $at;
            $at += 1 + strcspn($html, self::SPACE . '/>=', $at + 1);
            $isHref = $at - $nameStart === 4 && strcasecmp(substr($html, $nameStart, length: 4), 'href') === 0;
            $value = self::value($html, $at);
            if ($value === false) {
                return false;
            }
            if ($isHref && $href === null) {
                $href = $value ?? '';
            }
        }
    }

    /**
     * The > that ends the first closing tag of $name at or after $from, or false when there is none or it never
     * ends: then the content runs to the end of the markup.
     */
    public static function closer(string $html, string $name, int $from): int|false
    {
        $closer = '</' . $name;
        while (($start = stripos($html, $closer, $from)) !== false) {
            $from = $start + strlen($closer);
            if (self::endsName($html, $from)) {
                return self::end($html, $from);
            }
        }

        return false;
    }

    /** ASCII only: a letter of the locale, or a byte of a multibyte character, never starts a tag. */
    public static function isLetter(string $html, int $at): bool
    {
        return strspn($html, self::LETTERS, $at, length: 1) === 1;
    }

    /** Whether a tag name ends at $at; the end of the markup does not end one. */
    private static function endsName(string $html, int $at): bool
    {
        return strspn($html, self::SPACE . '/>', $at, length: 1) === 1;
    }

    /**
     * The value of the attribute whose name ends at $at, moving $at past it: null when it has none, false
     * when its quote never closes. Unquoted, a value runs to a space or >, quotes included.
     */
    private static function value(string $html, int &$at): string|false|null
    {
        $equals = $at + strspn($html, self::SPACE, $at);
        if (substr($html, $equals, length: 1) !== '=') {
            $at = $equals;

            return null;
        }

        $at = $equals + 1 + strspn($html, self::SPACE, $equals + 1);
        $quote = substr($html, $at, length: 1);
        if ($quote === '"' || $quote === "'") {
            $close = strpos($html, $quote, $at + 1);
            if ($close === false) {
                return false;
            }
            $value = substr($html, $at + 1, $close - $at - 1);
            $at = $close + 1;

            return $value;
        }

        $valueEnd = $at + strcspn($html, self::SPACE . '>', $at);
        $value = substr($html, $at, $valueEnd - $at);
        $at = $valueEnd;

        return $value;
    }
}
