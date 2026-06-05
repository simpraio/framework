<?php

declare(strict_types=1);

namespace core\cache;

use core\Instance;
use stdClass;

/**
 * Two-tier cache: per-request Memory backed by APCu when available.
 *
 * Reads check Memory first, then APCu (promoting hits to Memory).
 * TTL applies to APCu only; Memory entries live for the request.
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
     * Store $value only if the key does not already exist (cross-process).
     * Does not write to the Memory tier; semantics only make sense in APCu.
     */
    public static function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return self::apcu()->add($key, $value, $ttl);
    }

    /**
     * Atomically increment a counter in APCu. Creates the key if absent.
     * Invalidates the Memory tier entry so subsequent reads see the updated value.
     * Returns false when APCu is unavailable.
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
     * Run $callback at most once per TTL across concurrent processes.
     * Uses APCu when available, otherwise a host-local file sentinel.
     */
    public static function once(string $key, callable $callback, int $ttl, float $waitSeconds = 0.0): bool
    {
        $apcu = self::apcu();
        if (!$apcu->enabled()) {
            return self::onceFile($key, $callback, $ttl);
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
     * File-mtime fallback for {@see once()} when APCu is disabled. A zero-byte
     * sentinel is created under sys_get_temp_dir() and its mtime is touched after
     * the callback runs; subsequent calls within $ttl seconds short-circuit.
     * flock() guards against concurrent runs on the same host. If the sentinel
     * cannot be opened, the callback runs unguarded.
     */
    private static function onceFile(string $key, callable $callback, int $ttl): bool
    {
        $path = sys_get_temp_dir() . '/cache-once-' . sha1(Instance::prefix() . $key);
        $now = time();

        clearstatcache(true, $path);
        $existed = file_exists($path);
        if ($existed && $ttl > 0) {
            $mtime = self::silent(static fn(): int|false => filemtime($path));
            if ($mtime !== false && ($now - $mtime) < $ttl) {
                return false;
            }
        }

        $fp = self::silent(static fn() => fopen(filename: $path, mode: 'c'));
        if ($fp === false) {
            $callback();
            return true;
        }

        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                return false;
            }

            if ($existed && $ttl > 0) {
                clearstatcache(true, $path);
                $mtime = self::silent(static fn(): int|false => filemtime($path));
                if ($mtime !== false && ($now - $mtime) < $ttl) {
                    return false;
                }
            }

            $callback();
            self::silent(static fn(): bool => touch($path));
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Get value or compute, store, and return it.
     *
     * Uses an APCu atomic-add lock so only one process computes on a cold-cache miss.
     * Losers wait 5 ms and re-check before falling back to computing themselves.
     * When APCu is unavailable the lock is skipped and all callers may compute.
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

        $value = $callback();
        self::set($key, $value, $ttl);

        if ($lockKey !== null) {
            $apcu->delete($lockKey);
        }

        return $value;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private static function silent(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
