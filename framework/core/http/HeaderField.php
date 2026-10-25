<?php

declare(strict_types=1);

namespace core\http;

/**
 * What an HTTP header name and value may contain, for responses and outbound requests alike.
 */
final class HeaderField
{
    /** An RFC 9110 token: no spaces, colons, controls or non-ASCII. */
    public static function isName(string $name): bool
    {
        return preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) === 1;
    }

    /** CR or LF would end the field and start another; NUL silently truncates it in C libraries such as cURL. */
    public static function isValue(string $value): bool
    {
        return preg_match('/[\r\n\0]/', $value) !== 1;
    }
}
