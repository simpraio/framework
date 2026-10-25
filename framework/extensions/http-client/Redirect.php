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

    /** Where a Location header points, resolved against the URL that returned it. */
    public static function target(string $base, string|array|null $location): ?string
    {
        if (is_array($location)) {
            // Several Location headers: the last one wins, as a client reading them in
            // order would end up believing.
            $key = array_key_last($location);
            $location = $key !== null && is_string($location[$key]) ? $location[$key] : null;
        }

        if (!is_string($location) || trim($location) === '') {
            return null;
        }

        return Reference::resolve($base, trim($location));
    }
}
