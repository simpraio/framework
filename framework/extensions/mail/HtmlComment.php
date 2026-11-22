<?php

declare(strict_types=1);

namespace extensions\mail;

/**
 * Where an HTML comment ends, read as a browser reads it.
 *
 * @internal used by PlainText
 */
final class HtmlComment
{
    /**
     * The > that ends a comment whose body starts at $at, or false when the markup ends first: "-->" or "--!>";
     * "<!-->" and "<!--->" are empty comments. Each "--" is looked at once, so the time grows with the body.
     */
    public static function end(string $html, int $at): int|false
    {
        if (substr($html, $at, length: 1) === '>' || substr($html, $at, length: 2) === '->') {
            return strpos($html, needle: '>', offset: $at);
        }
        while (($dashes = strpos($html, needle: '--', offset: $at)) !== false) {
            $next = substr($html, $dashes + 2, length: 2);
            if (str_starts_with($next, '>') || $next === '!>') {
                return $dashes + ($next === '!>' ? 3 : 2);
            }
            $at = $dashes + 1;
        }

        return false;
    }
}
