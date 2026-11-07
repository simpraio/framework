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
 * Fixed-window limits per IP+account and per IP; never per account alone,
 * which would let unauthenticated attackers lock out another host's account.
 * Every attempt is counted before it is checked, so a burst of concurrent attempts cannot all pass a
 * limit none of them has counted yet. APCu and the locked-file fallback coordinate only on one host.
 */
final class RateLimit
{
    /** $subject names the account, or the name tried when none matches. */
    private static function pairKey(string $subject): string
    {
        return 'auth.login.rl.' . Identifier::fastHash($subject . '|' . Request::ip());
    }

    private static function ipKey(): string
    {
        return 'auth.login.rl.ip.' . Identifier::fastHash(Request::ip());
    }

    public static function ipAttempt(): bool
    {
        $config = Config::enabled();

        return self::count(self::ipKey(), 1, $config->rateLimitWindow) <= $config->rateLimitAttempts * 4;
    }

    public static function accountAttempt(string $subject): bool
    {
        $config = Config::enabled();

        return self::count(self::pairKey($subject), 1, $config->rateLimitWindow) <= $config->rateLimitAttempts;
    }

    /**
     * Hands the attempt back to the IP's count without resetting it, so a successful login does not
     * restore an enumeration budget, and clears the account's.
     */
    public static function succeeded(string $subject): void
    {
        $config = Config::enabled();

        Cache::delete(self::pairKey($subject));
        self::fileDelete(self::pairKey($subject));
        self::count(self::ipKey(), -1, $config->rateLimitWindow);
    }

    private static function count(string $key, int $step, int $window): int
    {
        // Checked before APCu counts anything: a successful login clears the file counter as well,
        // and an unsafe directory must refuse the attempt here, not after the session holds the user.
        CounterDirectory::path();

        $count = Cache::inc($key, $step, $window);
        if ($count === false) {
            return self::fileInc($key, $window, $step);
        }
        // A hand-back after its window expired starts a new counter below zero; the file fallback floors it too.
        if ($count < 0) {
            Cache::delete($key);
            return 0;
        }

        return $count;
    }

    private static function fileInc(string $key, int $window, int $step): int
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

            $counter = self::nextCounter(self::fileReadHandle($fp), time(), $window, $step);
            self::fileWriteHandle($fp, $counter);

            return $counter['attempts'];
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
    private static function nextCounter(?array $counter, int $now, int $window, int $step): array
    {
        if ($counter === null || $counter['expires'] <= $now) {
            return ['attempts' => max(0, $step), 'expires' => $now + $window];
        }

        return ['attempts' => max(0, $counter['attempts'] + $step), 'expires' => $counter['expires']];
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
