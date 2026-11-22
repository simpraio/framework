<?php

declare(strict_types=1);

namespace core\log;

use core\config\Config;
use RuntimeException;
use Throwable;

/**
 * Text for PHP's error_log() and a command line's STDERR. It comes from exceptions and rows, so it gets
 * what a line of the log file gets: configured secrets stripped, one line, bounded.
 */
final class NativeLog
{
    /** Null on an incomplete secret scan, requiring caller text to be discarded rather than partially redacted. */
    public static function line(string $text): ?string
    {
        return self::lines([$text])[0] ?? null;
    }

    /**
     * line() for each text, collecting the secrets once; null, for all of them, on an incomplete scan.
     *
     * @param list<string> $texts
     * @return list<string>|null
     */
    public static function lines(array $texts): ?array
    {
        $secrets = self::secrets();
        if ($secrets === null) {
            return null;
        }

        return array_map(static fn(string $text): string => Text::escape(Text::cap($secrets->strip($text))), $texts);
    }

    /**
     * The configured secrets, or null when their scan stopped at its limits. Before configuration is
     * loaded there is no secret to strip, and reporting stays possible.
     */
    public static function secrets(): ?Secrets
    {
        try {
            return new Secrets(Config::secrets());
        } catch (RuntimeException) {
            return null;
        } catch (Throwable) {
            return new Secrets([]);
        }
    }
}
