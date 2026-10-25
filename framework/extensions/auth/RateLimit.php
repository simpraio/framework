<?php

declare(strict_types=1);

namespace extensions\auth;

use core\cache\Cache;
use core\Instance;
use core\Request;
use core\tools\Identifier;
use core\tools\Silently;
use RuntimeException;

/**
 * Fixed-window limits per IP+username and per IP; never per username alone,
 * which would let unauthenticated attackers lock out another host's account.
 * APCu and the locked-file fallback coordinate only on one host.
 */
final class RateLimit
{
    private static function pairKey(string $username): string
    {
        return 'auth.login.rl.' . Identifier::fastHash(strtolower($username) . '|' . Request::ip());
    }

    private static function ipKey(): string
    {
        return 'auth.login.rl.ip.' . Identifier::fastHash(Request::ip());
    }

    public static function blocked(string $username): bool
    {
        $config = Config::enabled();

        $pairKey = self::pairKey($username);
        $ipKey = self::ipKey();

        $pairAttempts = max((int) Cache::get($pairKey, 0), self::fileGet($pairKey));
        if ($pairAttempts >= $config->rateLimitAttempts) {
            return true;
        }

        $ipAttempts = max((int) Cache::get($ipKey, 0), self::fileGet($ipKey));
        return $ipAttempts >= $config->rateLimitAttempts * 4;
    }

    public static function fail(string $username): void
    {
        $config = Config::enabled();

        $window = $config->rateLimitWindow;
        $pairKey = self::pairKey($username);
        $ipKey = self::ipKey();

        $pairCount = Cache::inc($pairKey, 1, $window);
        $ipCount = Cache::inc($ipKey, 1, $window);

        if ($pairCount === false || $ipCount === false) {
            self::fileInc($pairKey, $window);
            self::fileInc($ipKey, $window);
        }
    }

    /**
     * A successful login must not reset the IP's enumeration budget.
     */
    public static function clear(string $username): void
    {
        Config::enabled();

        Cache::delete(self::pairKey($username));
        self::fileDelete(self::pairKey($username));
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

        $fp = Silently::call(static fn() => fopen(filename: $path, mode: 'c+'));
        if ($fp === false) {
            throw new RuntimeException("AUTH_RATE_LIMIT_FILE_FAILED: {$path}");
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException("AUTH_RATE_LIMIT_FILE_LOCK_FAILED: {$path}");
            }

            $counter = self::fileReadHandle($fp);
            self::fileWriteHandle($fp, self::nextCounter($counter, time(), $window));

            return;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Preserve the original expiry, matching APCu's fixed window.
     *
     * @param array{attempts: int, expires: int}|null $counter
     * @return array{attempts: int, expires: int}
     */
    private static function nextCounter(?array $counter, int $now, int $window): array
    {
        if ($counter === null || $counter['expires'] <= $now) {
            return ['attempts' => 1, 'expires' => $now + $window];
        }

        return ['attempts' => $counter['attempts'] + 1, 'expires' => $counter['expires']];
    }

    private static function fileDelete(string $key): void
    {
        $path = self::filePath($key);
        if (is_file($path)) {
            Silently::call(static fn(): bool => unlink($path));
        }
    }

    private static function filePath(string $key): string
    {
        return CounterDirectory::path() . '/' . Identifier::fastHash(Instance::prefix() . $key) . '.json';
    }

    /** @return array{attempts: int, expires: int}|null */
    private static function fileRead(string $path): ?array
    {
        $fp = Silently::call(static fn() => fopen(filename: $path, mode: 'r'));
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
}
