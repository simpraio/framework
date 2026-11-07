<?php

declare(strict_types=1);

namespace core\cache;

use core\Instance;
use core\tools\Silently;
use Throwable;

/**
 * Cache::once() without APCu: a host-local sentinel file per key, recording when the work last
 * completed. The sentinels live in the deployment's bundle directory, which no other account may write
 * to - a shared temporary directory would let one plant a link at a predictable name and have this
 * process write through it. Without a directory, or where no sentinel can be opened, the callback runs
 * unguarded.
 *
 * @internal
 */
final class OnceFile
{
    private static ?string $directory = null;

    /** Kernel::boot() points this at the bundle directory. */
    public static function init(string $directory): void
    {
        self::$directory = rtrim($directory, characters: '/\\');
    }

    public static function run(string $key, callable $callback, int $ttl): bool
    {
        if (self::$directory === null) {
            $callback();
            return true;
        }

        $path = self::$directory . '/once-' . sha1(Instance::prefix() . $key);
        if (self::ranWithin(Silently::call(static fn(): string|false => file_get_contents($path)), $ttl)) {
            return false;
        }

        $fp = Silently::call(static fn() => fopen(filename: $path, mode: 'c+'));
        if ($fp === false) {
            $callback();
            return true;
        }

        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                return false;
            }
            // Another process may have finished between the check above and this lock. The sentinel
            // records when the work completed: opening it creates it empty, so it says nothing by existing.
            if (self::ranWithin(stream_get_contents($fp, offset: 0), $ttl)) {
                return false;
            }

            try {
                $callback();
            } catch (Throwable $failure) {
                ftruncate($fp, size: 0);
                throw $failure;
            }
            // Reading the sentinel left the position at its old end; truncating does not move it.
            ftruncate($fp, size: 0);
            rewind($fp);
            fwrite($fp, (string)time());
            fflush($fp);
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** A TTL of 0 is once with no expiry, as in APCu. */
    private static function ranWithin(string|false $completedAt, int $ttl): bool
    {
        if ($completedAt === false || preg_match('/^\d+$/D', $completedAt) !== 1) {
            return false;
        }

        return $ttl <= 0 || time() - (int)$completedAt < $ttl;
    }
}
