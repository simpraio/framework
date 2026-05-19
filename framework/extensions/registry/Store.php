<?php

declare(strict_types=1);

namespace extensions\registry;

use core\cache\Cache;
use core\db\Db;
use RuntimeException;
use Throwable;

final class Store
{
    public const string LANGUAGE_AGNOSTIC = '';

    private static ?string $language = null;

    public static function setLanguage(string $language): void
    {
        Config::enabled();
        self::$language = $language;
    }

    public static function language(): string
    {
        Config::enabled();

        if (self::$language === null) {
            throw new RuntimeException(
                'Registry: route language not set; call Store::setLanguage() or pass language explicitly.',
            );
        }
        return self::$language;
    }

    public static function get(string $group, string $key, string $language): ?string
    {
        Config::enabled();
        return self::group($group, $language)[$key] ?? null;
    }

    /** @return array<string, string> */
    public static function group(string $group, string $language): array
    {
        $config = Config::enabled();

        try {
            return Cache::remember(
                self::cacheKey($group, $language),
                static fn(): array => self::fetch($group, $language),
                $config->cacheTtl,
            );
        } catch (Throwable) {
            return [];
        }
    }

    public static function clear(string $group, string $language): void
    {
        Config::enabled();
        Cache::delete(self::cacheKey($group, $language));
    }

    /** @return array<string, string> */
    private static function fetch(string $group, string $language): array
    {
        $tokens = [];
        foreach (
            Db::select(
                '
            SELECT
                `key`, `value`
            FROM
                `registry`
            WHERE
                `group` = :group AND 
                `language` = :language',
                ['group' => $group, 'language' => $language],
            ) as $row
        ) {
            if (!is_string($row['key'] ?? null) || !is_scalar($row['value'] ?? null)) {
                continue;
            }
            $tokens[$row['key']] = (string)$row['value'];
        }
        return $tokens;
    }

    private static function cacheKey(string $group, string $language): string
    {
        return 'registry.' . $group . '.' . ($language === self::LANGUAGE_AGNOSTIC ? '_' : $language);
    }
}
