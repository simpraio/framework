<?php

declare(strict_types=1);

namespace extensions\http_client;

final class Redirect
{
    public const int MAX = 5;

    public static function isStatus(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], strict: true);
    }

    public static function target(string $base, string|array|null $location): ?string
    {
        if (is_array($location)) {
            $key = array_key_last($location);
            $location = $key !== null && is_string($location[$key]) ? $location[$key] : null;
        }
        if (!is_string($location) || trim($location) === '') {
            return null;
        }

        $location = trim($location);
        if (is_string(parse_url($location, PHP_URL_SCHEME))) {
            return $location;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host)) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return strtolower($scheme) . ':' . $location;
        }

        $authority = self::authority($scheme, $host, parse_url($base, PHP_URL_PORT));
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        $path = parse_url($base, PHP_URL_PATH);
        $dirRaw = is_string($path)
            ? preg_replace(pattern: '~/[^/]*$~', replacement: '/', subject: $path)
            : '/';
        $dir = is_string($dirRaw) ? $dirRaw : '/';
        $prefix = $dir !== '' ? $dir : '/';

        return $authority . self::normalizePath($prefix . $location);
    }

    private static function authority(string $scheme, string $host, mixed $port): string
    {
        $authority = strtolower($scheme) . '://' . $host;
        return is_int($port) ? $authority . ':' . $port : $authority;
    }

    private static function normalizePath(string $path): string
    {
        $query = '';
        $queryPos = strpos(haystack: $path, needle: '?');
        if ($queryPos !== false) {
            $query = substr(string: $path, offset: $queryPos);
            $path = substr(string: $path, offset: 0, length: $queryPos);
        }

        $out = [''];
        foreach (explode(separator: '/', string: $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
                continue;
            }
            $out[] = $segment;
        }

        return implode(separator: '/', array: $out) . $query;
    }
}
