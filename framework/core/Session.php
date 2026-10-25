<?php

declare(strict_types=1);

namespace core;

use core\session\Store;

final class Session
{
    /** Where the CSRF token lives; rotate() drops it so a token issued before sign-in dies with that session. */
    public const string CSRF_KEY = '_csrf_token';

    private static Store $store;

    public static function init(Store $store): void
    {
        self::$store = $store;
        $store->configure();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$store->get($key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        self::$store->set($key, $value);
    }

    public static function has(string $key): bool
    {
        return self::$store->has($key);
    }

    public static function forget(string $key): void
    {
        self::$store->forget($key);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        return self::$store->pull($key, $default);
    }

    public static function regenerate(bool $deleteOld = true): void
    {
        self::$store->regenerate($deleteOld);
    }

    /** Call on sign-in or any other gain in privilege: a new session ID, the old one invalidated, and a fresh CSRF token. */
    public static function rotate(): void
    {
        self::$store->regenerate();
        self::$store->forget(self::CSRF_KEY);
    }

    public static function destroy(): void
    {
        self::$store->destroy();
    }

    public static function isStarted(): bool
    {
        return self::$store->isStarted();
    }

    /** Whether this request presented a non-empty session cookie, without starting one. */
    public static function hasCookie(): bool
    {
        return self::$store->hasCookie();
    }
}
