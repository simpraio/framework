<?php

declare(strict_types=1);

namespace extensions\error_log;

use core\config\Config as CoreConfig;
use core\db\Db;
use core\log\NativeLog;
use core\log\Secrets;
use core\log\SensitiveKeys;
use core\Request;
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
            // Null on an incomplete secret scan: the text is then discarded rather than partially redacted.
            $secrets = NativeLog::secrets();
            // Inserts and retention use the database's UTC clock.
            Db::insert('error_log', [
                'exception' => self::ascii($e::class, 191),
                'message' => self::truncate($secrets?->strip($e->getMessage()) ?? Secrets::UNCOLLECTED, 65_535),
                'file' => self::truncate($e->getFile(), 512),
                'line' => $e->getLine(),
                'trace' => $config->storeTrace
                    ? self::truncate($secrets?->strip($e->getTraceAsString()) ?? Secrets::UNCOLLECTED, 16_777_215)
                    : '',
                'url' => self::truncate(self::scrubUrl(Request::uri(), self::redactKeys($config)), 2048),
            ]);
        } catch (Throwable $sinkFailure) {
            self::report($sinkFailure);
        }
    }

    /** A retention of 0 or less keeps every row. */
    public static function purge(int $retentionDays): void
    {
        Config::enabled();

        if ($retentionDays < 1) {
            return;
        }

        try {
            Db::execute(
                'DELETE FROM `error_log` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$retentionDays],
            );
        } catch (Throwable $sinkFailure) {
            self::report($sinkFailure);
        }
    }

    private static function report(Throwable $sinkFailure): void
    {
        error_log('errors sink failed: ' . (NativeLog::line($sinkFailure->getMessage()) ?? Secrets::UNCOLLECTED));
    }

    /**
     * log.redact_keys as well as this extension's own: a key redacted from the log file
     * must not then be stored in the database.
     *
     * @return list<string>
     */
    private static function redactKeys(Config $config): array
    {
        try {
            $shared = CoreConfig::log()->redactKeys;
        } catch (Throwable) {
            $shared = [];
        }

        return array_values(array_unique([...$shared, ...$config->redactKeys]));
    }

    /**
     * Scrub query values only; leave the rest of the URL untouched. SensitiveKeys decides which
     * names are secret, so its built-in names are redacted besides $needles.
     *
     * @param list<string> $needles
     */
    private static function scrubUrl(string $url, array $needles): string
    {
        if (($qPos = strpos(haystack: $url, needle: '?')) === false) {
            return $url;
        }
        $sensitiveKeys = SensitiveKeys::index($needles);

        $base = substr(string: $url, offset: 0, length: $qPos);
        $rest = substr($url, $qPos + 1);

        $fragment = '';
        $hashPos = strpos(haystack: $rest, needle: '#');
        if ($hashPos !== false) {
            $fragment = substr($rest, $hashPos);
            $rest = substr(string: $rest, offset: 0, length: $hashPos);
        }

        // Split where PHP splits: every character of arg_separator.input, not "&" alone.
        $separators = (string)ini_get('arg_separator.input');
        $separators = $separators !== '' ? $separators : '&';
        $parts = preg_split('/([' . preg_quote($separators, delimiter: '/') . '])/', $rest, flags: PREG_SPLIT_DELIM_CAPTURE);
        $parts = $parts !== false ? $parts : [$rest];
        foreach ($parts as $i => $pair) {
            // Odd entries are the separators themselves.
            if ($i % 2 === 1 || $pair === '') {
                continue;
            }
            $eq = strpos(haystack: $pair, needle: '=');
            $name = $eq === false ? $pair : substr(string: $pair, offset: 0, length: $eq);
            if (self::isRedacted($name, $sensitiveKeys)) {
                $parts[$i] = $name . '=' . urlencode('[REDACTED]');
            }
        }

        return $base . '?' . implode('', $parts) . $fragment;
    }

    /**
     * Check raw and PHP-normalized names: token%00x and token[a] both become token in $_GET.
     *
     * @param array<string, true> $sensitiveKeys from SensitiveKeys::index()
     */
    private static function isRedacted(string $name, array $sensitiveKeys): bool
    {
        $parsed = [];
        parse_str($name . '=', $parsed);
        // A numeric name comes back as an int key.
        $asRead = strval(array_key_first($parsed) ?? '');

        return SensitiveKeys::matches(urldecode($name), $sensitiveKeys)
            || SensitiveKeys::matches($asRead, $sensitiveKeys);
    }

    /**
     * The `exception` column is ASCII, and an anonymous class name carries a NUL and the path of
     * the file declaring it, which may not be; any other byte becomes `?`.
     */
    private static function ascii(string $value, int $max): string
    {
        return substr((string)preg_replace('/[^\x20-\x7E]/', replacement: '?', subject: $value), offset: 0, length: $max);
    }

    /** Valid UTF-8, cut at a character boundary within $max bytes. */
    private static function truncate(string $value, int $max): string
    {
        return mb_strcut(mb_scrub($value, encoding: 'UTF-8'), start: 0, length: $max, encoding: 'UTF-8');
    }
}
