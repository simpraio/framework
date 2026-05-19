<?php

declare(strict_types=1);

namespace extensions\auth;

use core\cache\Cache;
use core\db\Db;

final class Access
{
    public static function isControlled(string $pathId): bool
    {
        Config::enabled();

        return Cache::remember(
            'access.ctrl.' . $pathId,
            static fn(): bool => Db::row(
                    "SELECT 1 FROM `auth_access` WHERE `path_id` = :path_id AND `block` = '' LIMIT 1",
                    ['path_id' => $pathId]
                ) !== null,
            3600
        );
    }

    public static function allowedPath(string $pathId): bool
    {
        Config::enabled();

        foreach (self::rows($pathId) as $row) {
            if (($row['block'] ?? '') === '') {
                return ($row['policy'] ?? '') === 'allow';
            }
        }
        return false;
    }

    /**
     * Resolve per-block visibility for the current user.
     *
     * Precedence (most specific wins): user-specific row > group-specific row > global row.
     * Implemented via SQL ORDER BY `block`, `user_id` DESC, `group_id` DESC plus `??=`,
     * which keeps the first row seen per block and ignores later, less-specific rows.
     * As a result, a user-level `allow` overrides a group-level `deny` for the same block.
     *
     * @return array<string, bool> block name => visible
     */
    public static function pathBlocks(string $pathId): array
    {
        Config::enabled();

        $blocks = [];
        foreach (self::rows($pathId) as $row) {
            $name = (string)($row['block'] ?? '');
            if ($name !== '') {
                $blocks[$name] ??= ($row['policy'] ?? '') === 'allow';
            }
        }
        return $blocks;
    }

    /** @var array<string, list<array<string, mixed>>> */
    private static array $cache = [];

    /** @return list<array<string, mixed>> */
    private static function rows(string $pathId): array
    {
        $params = self::userParams();
        $key = $pathId . '.' . $params['user_id'] . '.' . $params['group_id'];

        return self::$cache[$key] ??= Db::select(
            "
            SELECT
                `block`, `policy`
            FROM
                `auth_access`
            WHERE
                `path_id` = :path_id AND
                (`user_id` = 0 OR `user_id` = :user_id) AND
                (`group_id` = 0 OR `group_id` = :group_id)
            ORDER BY
                `block`, `user_id` DESC, `group_id` DESC",
            ['path_id' => $pathId, ...$params]
        );
    }

    /** @return array<string, int> */
    private static function userParams(): array
    {
        $user = User::current();
        return [
            'user_id'  => (int)($user->user_id ?? 0),
            'group_id' => (int)($user->group_id ?? 0),
        ];
    }
}
