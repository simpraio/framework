<?php

declare(strict_types=1);

namespace core\template;

/**
 * Template block markers: {Name}...{-Name}. Each opening marker pairs with the first closing
 * marker after it. Plain string search, not a regular expression: PCRE's backtrack limit gives up
 * on a large block, which would then be sent as it stands, hidden or not.
 */
final class Block
{
    public static function apply(string $content, string $name, bool $show): string
    {
        $open = '{' . $name . '}';
        $close = '{-' . $name . '}';
        $result = '';
        $offset = 0;

        while (($start = strpos($content, $open, $offset)) !== false) {
            $inner = $start + strlen($open);
            $end = strpos($content, $close, $inner);
            if ($end === false) {
                break;
            }

            $result .= substr($content, $offset, $start - $offset);
            if ($show) {
                $result .= substr($content, $inner, $end - $inner);
            }
            $offset = $end + strlen($close);
        }

        return $result . substr($content, $offset);
    }
}
