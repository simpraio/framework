<?php

declare(strict_types=1);

namespace core\http;

final class RouteSegment
{
    private const string CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789-';

    public static function normalize(string $segment): ?string
    {
        $segment = strtolower($segment);
        if ($segment === '' || strspn($segment, self::CHARS) !== strlen($segment)) {
            return null;
        }
        // A controller's class name drops the hyphens, so a leading, trailing or doubled one would give
        // the same controller another path - one a rule keyed on the path does not cover.
        if ($segment[0] === '-' || str_ends_with($segment, '-') || str_contains($segment, '--')) {
            return null;
        }

        return $segment;
    }

    /** @return list<string>|null */
    public static function split(string $path): ?array
    {
        $path = trim(string: $path, characters: '/');
        if ($path === '') {
            return [];
        }

        $segments = explode(separator: '/', string: $path);
        foreach ($segments as $i => $segment) {
            $segment = self::normalize($segment);
            if ($segment === null) {
                return null;
            }

            $segments[$i] = $segment;
        }

        return $segments;
    }
}
