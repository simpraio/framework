<?php

declare(strict_types=1);

namespace extensions\auth;

use core\http\Url;

final class GuestReturn
{
    /**
     * Sign-in returns with GET; never revisit a body-bearing method as a different request.
     */
    public static function append(string $target, string $uri, string $param, string $method): string
    {
        if ($param === ''
            || !in_array(strtoupper($method), ['GET', 'HEAD'], strict: true)
            || !Url::isLocal($uri)
        ) {
            return $target;
        }

        // Query strings, fragments and a trailing slash do not change the sign-in route.
        if (self::path($uri) === self::path($target)) {
            return $target;
        }

        $parts = explode('#', $target, limit: 2);
        $location = $parts[0];
        $fragment = array_key_exists(1, $parts) ? '#' . $parts[1] : '';

        return $location
            . (str_contains($location, '?') ? '&' : '?')
            . rawurlencode($param) . '=' . rawurlencode($uri)
            . $fragment;
    }

    private static function path(string $value): string
    {
        $path = rtrim(Url::path($value), characters: '/');

        return $path === '' ? '/' : $path;
    }
}
