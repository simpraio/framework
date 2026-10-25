<?php

declare(strict_types=1);

namespace extensions\translation;

use core\cache\Cache;
use core\cache\Optional;
use core\db\Db;

final class Store
{
    public const string LAYOUT_PATH_ID = 'layout';

    /** @return array<string, string> */
    public static function tokens(string $pathId, string $language): array
    {
        $config = Config::enabled();

        return Optional::remember(
            self::cacheKey($pathId, $language),
            static fn(): array => self::fetch($pathId, $language),
            $config->cacheTtl,
            [],
            'Translations unavailable; rendering message IDs',
        );
    }

    public static function clear(string $pathId, string $language): void
    {
        Config::enabled();

        Cache::delete(self::cacheKey($pathId, $language));
    }

    /** @return array<string, string> */
    private static function fetch(string $pathId, string $language): array
    {
        $tokens = [];
        foreach (
            Db::select(
                'SELECT `id`, `text` FROM `translation` WHERE `path_id` = :path_id AND `language` = :language',
                ['path_id' => $pathId, 'language' => $language],
            ) as $row
        ) {
            if (!is_string($row['id'] ?? null) || !is_scalar($row['text'] ?? null)) {
                continue;
            }
            $tokens[strtoupper($row['id'])] = (string)$row['text'];
        }
        return $tokens;
    }

    private static function cacheKey(string $pathId, string $language): string
    {
        return 'translation.' . $language . '.' . str_replace(search: '/', replace: '_', subject: $pathId);
    }
}
