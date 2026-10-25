<?php

declare(strict_types=1);

namespace extensions\auth;

use core\tools\Directory;
use core\tools\Silently;
use RuntimeException;

/**
 * File counters are checked even with APCu enabled. On POSIX, refuse links, group/other-writable
 * directories and foreign owners where ownership checks are available, so other accounts cannot
 * plant or reset counters. Windows requires deployment-managed ACLs instead.
 */
final class CounterDirectory
{
    /** @var string|null the directory, once checked in this request */
    private static ?string $path = null;

    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }
        $dir = sys_get_temp_dir() . '/simpra-auth-rl';
        Directory::ensure($dir, 0o700, 'AUTH_RATE_LIMIT_FILE_FAILED');
        if (DIRECTORY_SEPARATOR === '/' && !self::isPrivate($dir)) {
            throw new RuntimeException("AUTH_RATE_LIMIT_DIRECTORY_NOT_PRIVATE: {$dir}");
        }

        return self::$path = $dir;
    }

    private static function isPrivate(string $dir): bool
    {
        clearstatcache(true, $dir);
        $mode = Silently::call(static fn(): int|false => fileperms($dir));
        if (is_link($dir) || $mode === false || ($mode & 0o022) !== 0) {
            return false;
        }

        return !function_exists('posix_geteuid')
            || Silently::call(static fn(): int|false => fileowner($dir)) === posix_geteuid();
    }
}
