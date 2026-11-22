<?php

declare(strict_types=1);

namespace core\error;

use core\log\Writer;
use core\tools\Identifier;

/**
 * Deduplicate and cap deprecations to avoid repeated locked writes in loops.
 * Static state lasts for the request, or the whole run in a long-lived CLI worker.
 */
final class Deprecations
{
    private const int MAX = 100;

    /** @var array<string, true> hashes of file, line and message already recorded */
    private static array $seen = [];

    /** The hash is stored before the write, so a deprecation the write itself raises is not recorded again. */
    public static function record(?Writer $log, string $message, string $file, int $line): void
    {
        $count = count(self::$seen);
        if ($count > self::MAX) {
            return;
        }

        $key = Identifier::fastHash($file . "\0" . $line . "\0" . $message);
        if (array_key_exists($key, self::$seen)) {
            return;
        }
        self::$seen[$key] = true;

        if ($count === self::MAX) {
            $log?->log(Writer::WARNING, 'Deprecations: ' . self::MAX . ' recorded in this request, further ones are not');
            return;
        }
        $log?->log(Writer::WARNING, 'Deprecated: ' . $message, ['file' => $file, 'line' => $line]);
    }
}
