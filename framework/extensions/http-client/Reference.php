<?php

declare(strict_types=1);

namespace extensions\http_client;

/** RFC 3986 section 5; dot-segment removal applies only to the path, never query or fragment. */
final class Reference
{
    /** The absolute URL a reference names, or null when the base cannot anchor it. */
    public static function resolve(string $base, string $reference): ?string
    {
        $parts = parse_url($reference);
        if ($parts === false) {
            return null;
        }
        if (array_key_exists('scheme', $parts)) {
            return self::absolute($reference, $parts['path'] ?? '');
        }

        $source = parse_url($base);
        if ($source === false) {
            return null;
        }
        $scheme = $source['scheme'] ?? null;
        $host = $source['host'] ?? null;
        if (!is_string($scheme) || !is_string($host)) {
            return null;
        }

        [$path, $query, $fragment] = self::split($reference);

        if (str_starts_with($reference, '//')) {
            return strtolower($scheme) . ':' . self::absolute($reference, $parts['path'] ?? '');
        }

        $authority = self::authority($source);
        $basePathValue = $source['path'] ?? null;
        $basePath = is_string($basePathValue) ? $basePathValue : '';

        if ($path !== '') {
            $resolved = DotSegments::remove(
                str_starts_with($path, '/') ? $path : self::merge($basePath, $path),
            );

            return $authority . $resolved . $query . $fragment;
        }

        // A missing query inherits the base query; an explicitly empty one replaces it.
        if ($query === '') {
            $baseQuery = $source['query'] ?? null;
            $query = is_string($baseQuery) ? '?' . $baseQuery : '';
        }

        return $authority . ($basePath !== '' ? $basePath : '/') . $query . $fragment;
    }

    private static function absolute(string $reference, string $path): string
    {
        [$head, $query, $fragment] = self::split($reference);
        $authority = substr(string: $head, offset: 0, length: strlen($head) - strlen($path));

        return $authority . DotSegments::remove($path) . $query . $fragment;
    }

    /**
     * A reference as path, query and fragment, each keeping its delimiter.
     *
     * The fragment comes off first: a `?` after a `#` belongs to the fragment.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function split(string $reference): array
    {
        $fragment = '';
        $hash = strpos(haystack: $reference, needle: '#');
        if ($hash !== false) {
            $fragment = substr(string: $reference, offset: $hash);
            $reference = substr(string: $reference, offset: 0, length: $hash);
        }

        $query = '';
        $mark = strpos(haystack: $reference, needle: '?');
        if ($mark !== false) {
            $query = substr(string: $reference, offset: $mark);
            $reference = substr(string: $reference, offset: 0, length: $mark);
        }

        return [$reference, $query, $fragment];
    }

    private static function merge(string $basePath, string $path): string
    {
        $slash = strrpos($basePath, needle: '/');

        return $slash === false
            ? '/' . $path
            : substr(string: $basePath, offset: 0, length: $slash + 1) . $path;
    }

    /** @param array<string, int|string> $parts */
    private static function authority(array $parts): string
    {
        $credentials = '';
        if (array_key_exists('user', $parts)) {
            $credentials = (string)$parts['user'];
            if (array_key_exists('pass', $parts)) {
                $credentials .= ':' . $parts['pass'];
            }
            $credentials .= '@';
        }
        $authority = strtolower((string)$parts['scheme']) . '://' . $credentials . $parts['host'];
        $port = $parts['port'] ?? null;

        return is_int($port) ? $authority . ':' . $port : $authority;
    }
}
