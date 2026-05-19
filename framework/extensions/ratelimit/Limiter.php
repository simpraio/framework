<?php

declare(strict_types=1);

namespace extensions\ratelimit;

use core\cache\Cache;
use core\tools\Identifier;

/**
 * General per-IP request throttling. Single axis: one counter per caller IP,
 * caller-supplied max and window per call.
 *
 * Fixed-window: TTL is anchored to the key's first creation and is not
 * refreshed by subsequent inc() calls. A caller pacing requests at just under
 * one per window can accumulate hits across boundaries. Acceptable for a
 * small-site framework; replace with a sliding-window limiter if needed.
 *
 * For login-attempt throttling (with two-axis tracking and reset-on-success),
 * see extensions/auth/RateLimit.
 */
final class Limiter
{
    public static function exceeded(string $ip, int $max, int $window): bool
    {
        Config::enabled();

        $key   = 'rl.' . Identifier::fastHash($ip);
        $count = Cache::inc($key, 1, $window);

        if ($count === false) {
            throw new \RuntimeException('Rate limiting requires APCu. Install the APCu extension or disable rate limiting.');
        }

        return $count > $max;
    }
}
