<?php

declare(strict_types=1);

namespace core\tools;

use core\config\Config;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Format
{
    private static ?DateTimeZone $timezone = null;

    public static function datetime(?float $timestamp = null, string $format = 'Y-m-d H:i:s.u'): string
    {
        $datetime = DateTimeImmutable::createFromFormat(
            format: 'U.u',
            datetime: sprintf('%.6f', $timestamp ?? microtime(true))
        );

        if (!$datetime) {
            throw new InvalidArgumentException('INVALID_TIMESTAMP');
        }

        self::$timezone ??= new DateTimeZone(Config::project()->timezone);

        return $datetime->setTimezone(self::$timezone)->format($format);
    }

    public static function slug(string $text): string
    {
        $text = self::lowercase(self::ascii(strip_tags(trim($text))));
        $text = str_replace(['\'', '`', "\xc2\xb4", '^', '~'], replace: '', subject: $text);

        return trim(
            string: preg_replace(pattern: '/[^a-z0-9]+/', replacement: '-', subject: $text) ?? '',
            characters: '-'
        );
    }

    public static function ascii(string $text): string
    {
        if (function_exists('iconv')) {
            $normalized = iconv(from_encoding: 'UTF-8', to_encoding: 'ASCII//TRANSLIT//IGNORE', string: $text);

            if ($normalized !== false && $normalized !== '') {
                return $normalized;
            }
        }

        return $text;
    }

    public static function uppercase(string $text): string
    {
        return mb_strtoupper($text, encoding: 'UTF-8');
    }

    public static function lowercase(string $text): string
    {
        return mb_strtolower($text, encoding: 'UTF-8');
    }

    public static function template(string $text, array $data = []): string
    {
        if ($data === []) {
            return $text;
        }

        $replacements = [];
        foreach (array_keys($data) as $key) {
            /** @var mixed $value */
            $value = $data[$key];
            $replacements['{' . strtoupper((string)$key) . '}'] = (string)$value;
        }

        return strtr($text, $replacements);
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars(
            string: trim((string)$value),
            flags: ENT_QUOTES | ENT_SUBSTITUTE,
            encoding: 'UTF-8'
        );
    }
}
