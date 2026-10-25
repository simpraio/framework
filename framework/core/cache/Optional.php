<?php

declare(strict_types=1);

namespace core\cache;

use core\Log;
use Exception;
use Throwable;

/**
 * Cached data a page can render without. A failure is answered with the fallback and logged once
 * per key per request: later calls in the same request get the fallback straight away instead of
 * retrying, and nothing is cached, so the next request tries again. Only exceptions are caught;
 * an Error is a bug and propagates.
 */
final class Optional
{
    /** @var array<string, true> keys that failed in this request */
    private static array $failed = [];

    /**
     * @template T
     * @param callable(): T $callback
     * @param T $fallback
     * @return T
     */
    public static function remember(string $key, callable $callback, int $ttl, mixed $fallback, string $failure): mixed
    {
        if (array_key_exists($key, self::$failed)) {
            return $fallback;
        }

        try {
            return Cache::remember($key, $callback, $ttl);
        } catch (Exception $e) {
            self::$failed[$key] = true;
            self::report($failure, $key, $e);

            return $fallback;
        }
    }

    private static function report(string $failure, string $key, Exception $e): void
    {
        // @mago-expect lint:no-empty-catch-clause -- logging may be unavailable and must not turn a fallback into an error.
        try {
            Log::warning($failure, ['key' => $key, 'error' => $e::class]);
        } catch (Throwable) {
        }
    }
}
