<?php

declare(strict_types=1);

namespace extensions\http_client;

/**
 * RFC 3986 section 5.2.4's remove_dot_segments. Splitting on slashes loses trailing and
 * repeated slashes, which can identify different resources.
 */
final class DotSegments
{
    public static function remove(string $path): string
    {
        $out = '';

        while ($path !== '') {
            // 2A
            if (str_starts_with($path, '../')) {
                $path = substr(string: $path, offset: 3);
                continue;
            }
            if (str_starts_with($path, './')) {
                $path = substr(string: $path, offset: 2);
                continue;
            }

            // 2B
            if (str_starts_with($path, '/./')) {
                $path = '/' . substr(string: $path, offset: 3);
                continue;
            }
            if ($path === '/.') {
                $path = '/';
                continue;
            }

            // 2C
            if (str_starts_with($path, '/../')) {
                $path = '/' . substr(string: $path, offset: 4);
                $out = self::dropSegment($out);
                continue;
            }
            if ($path === '/..') {
                $path = '/';
                $out = self::dropSegment($out);
                continue;
            }

            // 2D
            if ($path === '.' || $path === '..') {
                $path = '';
                continue;
            }

            // 2E: move the leading segment, including the slash that starts it.
            $next = strpos(haystack: $path, needle: '/', offset: 1);
            $out .= $next === false ? $path : substr(string: $path, offset: 0, length: $next);
            $path = $next === false ? '' : substr(string: $path, offset: $next);
        }

        return $out;
    }

    private static function dropSegment(string $out): string
    {
        $slash = strrpos(haystack: $out, needle: '/');

        return $slash === false ? '' : substr(string: $out, offset: 0, length: $slash);
    }
}
