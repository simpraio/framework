<?php

declare(strict_types=1);

namespace extensions\auth;

use core\cache\Cache;
use core\Instance;
use core\Request;
use core\tools\Identifier;
use RuntimeException;

/**
 * Login throttling. Two axes:
 *   - per-IP+username: tight (rateLimitAttempts) - stops single-IP brute force.
 *   - per-username:    looser (rateLimitAttempts * 4) - caps distributed
 *                      enumeration of one account from many IPs.
 *
 * Fixed-window: each counter's TTL is anchored to its first creation and is
 * not refreshed by subsequent fail() calls. An attacker pacing failures at
 * just under one per window can accumulate hits across boundaries. Acceptable
 * for a small-site framework; replace with a sliding-window limiter if needed.
 *
 * APCu is used when available. Without APCu, auth falls back to small locked
 * files under sys_get_temp_dir(); this is host-local and intended for simple
 * single-server deployments.
 *
 * For general per-IP request throttling unrelated to login, see
 * extensions/ratelimit/Limiter.
 */
final class RateLimit
{
    private static function ipKey(string $username): string
    {
        return 'auth.login.rl.' . Identifier::fastHash(strtolower($username) . '|' . Request::ip());
    }

    private static function userKey(string $username): string
    {
        return 'auth.login.rl.u.' . Identifier::fastHash(strtolower($username));
    }

    public static function blocked(string $username): bool
    {
        $config = Config::enabled();

        $ipKey = self::ipKey($username);
        $userKey = self::userKey($username);

        $ipAttempts = max((int) Cache::get($ipKey, 0), self::fileGet($ipKey));
        if ($ipAttempts >= $config->rateLimitAttempts) {
            return true;
        }

        $userAttempts = max((int) Cache::get($userKey, 0), self::fileGet($userKey));
        return $userAttempts >= $config->rateLimitAttempts * 4;
    }

    public static function fail(string $username): void
    {
        $config = Config::enabled();

        $window = $config->rateLimitWindow;
        $ipKey = self::ipKey($username);
        $userKey = self::userKey($username);

        $ipCount = Cache::inc($ipKey, 1, $window);
        $userCount = Cache::inc($userKey, 1, $window);

        if ($ipCount === false || $userCount === false) {
            self::fileInc($ipKey, $window);
            self::fileInc($userKey, $window);
        }
    }

    public static function clear(string $username): void
    {
        Config::enabled();

        Cache::delete(self::ipKey($username));
        Cache::delete(self::userKey($username));
        self::fileDelete(self::ipKey($username));
        self::fileDelete(self::userKey($username));
    }

    private static function fileGet(string $key): int
    {
        $path = self::filePath($key);
        $counter = self::fileRead($path);

        if ($counter === null || $counter['expires'] <= time()) {
            return 0;
        }

        return $counter['attempts'];
    }

    private static function fileInc(string $key, int $window): void
    {
        $path = self::filePath($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir(directory: $dir, permissions: 0o700, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException("AUTH_RATE_LIMIT_FILE_FAILED: {$dir}");
        }

        $fp = self::silent(static fn() => fopen(filename: $path, mode: 'c+'));
        if ($fp === false) {
            throw new RuntimeException("AUTH_RATE_LIMIT_FILE_FAILED: {$path}");
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException("AUTH_RATE_LIMIT_FILE_LOCK_FAILED: {$path}");
            }

            $counter = self::fileReadHandle($fp);
            $now = time();
            $attempts = ($counter === null || $counter['expires'] <= $now)
                ? 1
                : $counter['attempts'] + 1;

            self::fileWriteHandle($fp, [
                'attempts' => $attempts,
                'expires' => $now + $window,
            ]);

            return;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function fileDelete(string $key): void
    {
        $path = self::filePath($key);
        if (is_file($path)) {
            self::silent(static fn(): bool => unlink($path));
        }
    }

    private static function filePath(string $key): string
    {
        return sys_get_temp_dir() . '/simpra-auth-rl/'
            . Identifier::fastHash(Instance::prefix() . $key) . '.json';
    }

    /** @return array{attempts: int, expires: int}|null */
    private static function fileRead(string $path): ?array
    {
        $fp = self::silent(static fn() => fopen(filename: $path, mode: 'r'));
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return null;
            }

            return self::fileReadHandle($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param resource $fp
     * @return array{attempts: int, expires: int}|null
     */
    private static function fileReadHandle(mixed $fp): ?array
    {
        rewind($fp);
        $raw = stream_get_contents($fp);
        if ($raw === false || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var mixed $attempts */
        $attempts = $decoded['attempts'] ?? null;
        /** @var mixed $expires */
        $expires = $decoded['expires'] ?? null;
        if (!is_int($attempts) || !is_int($expires)) {
            return null;
        }

        return ['attempts' => $attempts, 'expires' => $expires];
    }

    /**
     * @param resource $fp
     * @param array{attempts: int, expires: int} $counter
     */
    private static function fileWriteHandle(mixed $fp, array $counter): void
    {
        $encoded = json_encode($counter, JSON_THROW_ON_ERROR);
        rewind($fp);
        ftruncate($fp, size: 0);
        fwrite($fp, $encoded);
        fflush($fp);
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
