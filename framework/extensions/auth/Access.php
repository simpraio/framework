<?php

declare(strict_types=1);

namespace extensions\auth;

use core\db\Db;

/** The current user's access, from `auth_access`. A path's rules are read once per request. */
final class Access
{
    /** @var array<string, Rules> path id => its rules */
    private static array $rules = [];

    public static function isControlled(string $pathId): bool
    {
        Config::enabled();
        return self::rules($pathId)->controlled();
    }

    public static function allowedPath(string $pathId): bool
    {
        Config::enabled();
        [$userId, $groupId] = self::user();
        return self::rules($pathId)->allowed($userId, $groupId);
    }

    /** @return array<string, bool> block name => visible, for every block configured for the path */
    public static function pathBlocks(string $pathId): array
    {
        Config::enabled();
        [$userId, $groupId] = self::user();
        return self::rules($pathId)->blocks($userId, $groupId);
    }

    private static function rules(string $pathId): Rules
    {
        return self::$rules[$pathId] ??= Rules::fromRows(Db::select(
            "
            SELECT
                `auth_access`.`block`, `auth_access`.`user_id`, `auth_access`.`group_id`,
                `auth_access`.`policy`, `auth_group`.`status`
            FROM
                `auth_access`
                LEFT JOIN `auth_group` ON `auth_group`.`group_id` = `auth_access`.`group_id`
            WHERE
                `auth_access`.`path_id` = :path_id",
            ['path_id' => $pathId]
        ));
    }

    /** @return array{int, int} user id, group id */
    private static function user(): array
    {
        $user = User::current();
        return [(int)($user->user_id ?? 0), (int)($user->group_id ?? 0)];
    }
}
