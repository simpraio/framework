<?php

declare(strict_types=1);

namespace extensions\seo;

use core\cache\Cache;
use core\db\Db;
use core\http\Route;
use Throwable;

final class Store
{
    /** @return array{title: string, description: string, canonical_url: string} */
    public static function page(Route $route): array
    {
        $config = Config::enabled();

        $pathId = $route->pathId();
        $key = "seo.{$pathId}.{$route->language}";

        try {
            return Cache::remember(
                $key,
                static fn(): array => self::row(
                    Db::row(
                        '
                        SELECT
                            `title`, `description`, `canonical_url`
                        FROM
                            `seo`
                        WHERE
                            `path_id` = :path_id AND
                            `language` = :language',
                        [
                            'path_id' => $pathId,
                            'language' => $route->language,
                        ]
                    )
                ),
                $config->cacheTtl,
            );
        } catch (Throwable) {
            return self::empty();
        }
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{title: string, description: string, canonical_url: string}
     */
    private static function row(?array $row): array
    {
        if ($row === null) {
            return self::empty();
        }

        return [
            'title' => self::string($row, 'title'),
            'description' => self::string($row, 'description'),
            'canonical_url' => self::string($row, 'canonical_url'),
        ];
    }

    /** @return array{title: string, description: string, canonical_url: string} */
    private static function empty(): array
    {
        return ['title' => '', 'description' => '', 'canonical_url' => ''];
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        if (!array_key_exists($key, $row)) {
            return '';
        }

        return self::clean($row[$key]);
    }

    private static function clean(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
