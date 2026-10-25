<?php

declare(strict_types=1);

namespace extensions\ratelimit;

use core\cache\Cache;
use core\tools\Identifier;

/**
 * Host-local fixed windows; increments do not extend the original expiry.
 */
final class Limiter
{
    /**
     * Whether this address has spent its budget under one policy.
     *
     * Each policy counts and expires on its own: the key covers `$max` and `$window` as
     * well as the address. `$scope` separates policies that differ only in where they
     * are applied - two call sites sharing one share a budget.
     */
    public static function exceeded(string $ip, int $max, int $window, string $scope = ''): bool
    {
        Config::enabled();

        $key   = 'rl.' . Identifier::fastHash($scope . '|' . $max . '|' . $window . '|' . $ip);
        $count = Cache::inc($key, 1, $window);

        if ($count === false) {
            throw new \RuntimeException('Rate limiting requires APCu. Install the APCu extension or disable rate limiting.');
        }

        return $count > $max;
    }
}
