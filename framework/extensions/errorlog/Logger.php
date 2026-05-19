<?php

declare(strict_types=1);

namespace extensions\errorlog;

use core\db\Db;
use core\Request;
use core\tools\Format;
use Throwable;

final class Logger
{
    public static function log(Throwable $e, ?Config $config = null): void
    {
        if ($config !== null) {
            Config::ensureEnabled($config);
        }

        $config ??= Config::enabled();

        try {
            Db::insert('error_log', [
                'created_at' => Format::datetime(),
                'exception' => self::truncate($e::class, 191),
                'message' => self::truncate($e->getMessage(), 65_535),
                'file' => self::truncate($e->getFile(), 512),
                'line' => $e->getLine(),
                'trace' => $config->storeTrace
                    ? self::truncate($e->getTraceAsString(), 16_777_215)
                    : '',
                'url' => self::truncate(self::scrubUrl(Request::uri(), $config->redactKeys), 2048),
            ]);
        } catch (Throwable $sinkFailure) {
            error_log('errors sink failed: ' . $sinkFailure->getMessage());
        }
    }

    public static function purge(int $retentionDays): void
    {
        Config::enabled();

        try {
            Db::execute(
                'DELETE FROM `error_log` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$retentionDays],
            );
        } catch (Throwable $sinkFailure) {
            error_log('errors sink failed: ' . $sinkFailure->getMessage());
        }
    }

    /**
     * Replace query-parameter values whose lowercased name is in $needles with
     * [REDACTED]. Path, host, and fragment untouched. Param names that fail to
     * url-decode are compared as-is.
     *
     * @param list<string> $needles already-lowercased
     */
    private static function scrubUrl(string $url, array $needles): string
    {
        if ($needles === [] || ($qPos = strpos(haystack: $url, needle: '?')) === false) {
            return $url;
        }

        $base = substr(string: $url, offset: 0, length: $qPos);
        $rest = substr($url, $qPos + 1);

        $fragment = '';
        $hashPos = strpos(haystack: $rest, needle: '#');
        if ($hashPos !== false) {
            $fragment = substr($rest, $hashPos);
            $rest = substr(string: $rest, offset: 0, length: $hashPos);
        }

        $pairs = explode('&', $rest);
        foreach ($pairs as $i => $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos(haystack: $pair, needle: '=');
            $name = $eq === false ? $pair : substr(string: $pair, offset: 0, length: $eq);
            if (in_array(strtolower(urldecode($name)), $needles, strict: true)) {
                $pairs[$i] = $name . '=' . urlencode('[REDACTED]');
            }
        }

        return $base . '?' . implode('&', $pairs) . $fragment;
    }

    private static function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, offset: 0, length: $max) : $value;
    }
}
