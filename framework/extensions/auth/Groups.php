<?php

declare(strict_types=1);

namespace extensions\auth;

use core\cache\Cache;
use core\db\Db;

final class Groups
{
    /** @return array<int, string> group_id => name */
    public static function all(): array
    {
        Config::enabled();

        return Cache::remember(
            'auth.groups',
            static function (): array {
                $result = [];
                foreach (
                    Db::select(
                        "
                    SELECT
                        `group_id`, `name`
                    FROM
                        `auth_group`
                    WHERE
                        `status` = 'active'"
                    ) as $row
                ) {
                    $result[(int)$row['group_id']] = (string)$row['name'];
                }
                return $result;
            },
            3600
        );
    }

    public static function name(int $groupId): ?string
    {
        Config::enabled();
        return self::all()[$groupId] ?? null;
    }

    public static function guestId(): int
    {
        $config = Config::enabled();
        $id = array_search($config->guestGroup, self::all(), strict: true);
        return $id === false ? 0 : (int)$id;
    }
}
