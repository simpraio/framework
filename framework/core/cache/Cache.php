<?php

declare(strict_types=1);

namespace core\cache;

use core\Instance;
use stdClass;

/**
 * TTL applies to APCu only; in-memory entries live for the request.
 */
final class Cache
{
    private static ?Memory $memory = null;
    private static ?Apcu $apcu = null;

    private static function memory(): Memory
    {
        return self::$memory ??= new Memory();
    }

    private static function apcu(): Apcu
    {
        return self::$apcu ??= new Apcu(Instance::prefix());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $memory = self::memory();
        if ($memory->has($key)) {
            return $memory->get($key);
        }

        $miss = new stdClass();
        /** @var mixed $value */
        $value = self::apcu()->get($key, $miss);

        if ($value === $miss) {
            return $default;
        }

        $memory->set($key, $value);
        return $value;
    }

    public static function has(string $key): bool
    {
        if (self::memory()->has($key)) {
            return true;
        }

        $miss = new stdClass();
        return self::apcu()->get($key, $miss) !== $miss;
    }

    public static function set(string $key, mixed $value, int $ttl = 0): bool
    {
        self::memory()->set($key, $value);
        $apcu = self::apcu();
        return !$apcu->enabled() || $apcu->set($key, $value, $ttl);
    }

    /**
     * APCu-only atomic add; does not populate the request-local tier.
     */
    public static function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return self::apcu()->add($key, $value, $ttl);
    }

    /**
     * Returns false without APCu; the request-local tier cannot provide atomic counters.
     */
    public static function inc(string $key, int $step = 1, int $ttl = 0): int|false
    {
        self::memory()->delete($key);
        return self::apcu()->inc($key, $step, $ttl);
    }

    public static function delete(string $key): void
    {
        self::memory()->delete($key);
        self::apcu()->delete($key);
    }

    public static function deletePrefix(string $prefix): void
    {
        self::memory()->deletePrefix($prefix);
        self::apcu()->deletePrefix($prefix);
    }

    /**
     * Host-local TTL suppression, not an exactly-once guarantee.
     * Uses APCu or a file sentinel; unavailable file storage runs the callback unguarded.
     */
    public static function once(string $key, callable $callback, int $ttl, float $waitSeconds = 0.0): bool
    {
        $apcu = self::apcu();
        if (!$apcu->enabled()) {
            return OnceFile::run($key, $callback, $ttl);
        }

        if (self::get($key) === true) {
            return false;
        }

        $ran = false;
        $lockTtl = $ttl > 0 ? min($ttl, 30) : 30;

        $apcu->once($key . "\0lock", static function () use ($key, $callback, $ttl, &$ran): void {
            if (self::get($key) === true) {
                return;
            }

            $callback();
            self::set($key, true, $ttl);
            $ran = true;
        }, $lockTtl, $waitSeconds);

        return $ran;
    }

    /**
     * Stampede protection is best-effort: callers may compute after waiting or without APCu.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function remember(string $key, callable $callback, int $ttl = 0): mixed
    {
        $miss = new stdClass();
        /** @var mixed $hit */
        $hit = self::get($key, $miss);

        if ($hit !== $miss) {
            /** @var T */
            return $hit;
        }

        $apcu = self::apcu();
        $lockKey = null;

        if ($apcu->enabled()) {
            $lockKey = $key . "\0lock";
            $lockTtl = $ttl > 0 ? $ttl : 30;

            if (!$apcu->add($lockKey, 1, $lockTtl)) {
                $lockKey = null;
                usleep(5_000);
                /** @var mixed $hit */
                $hit = self::get($key, $miss);
                if ($hit !== $miss) {
                    /** @var T */
                    return $hit;
                }
            }
        }

        try {
            $value = $callback();
            self::set($key, $value, $ttl);
        } finally {
            if ($lockKey !== null) {
                $apcu->delete($lockKey);
            }
        }

        return $value;
    }
}
