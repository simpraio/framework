<?php

declare(strict_types=1);

namespace extensions\auth;

use core\db\Db;

/** Groups from `auth_group`, read once per request so a status change applies at once. */
final class Groups
{
    /** @var array<int, array{name: string, active: bool}>|null group_id => name and status */
    private static ?array $groups = null;

    /** @return array<int, string> group_id => name, active groups only */
    public static function all(): array
    {
        Config::enabled();

        $active = array_filter(self::read(), static fn(array $group): bool => $group['active']);
        return array_map(static fn(array $group): string => $group['name'], $active);
    }

    public static function name(int $groupId): ?string
    {
        Config::enabled();
        return self::all()[$groupId] ?? null;
    }

    /**
     * Found by name whatever its status: a visitor keeps the guest group's id when it is disabled,
     * so the denials given to guests still match. Its grants are withdrawn like any disabled group's.
     */
    public static function guestId(): int
    {
        $config = Config::enabled();
        foreach (self::read() as $id => $group) {
            if ($group['name'] === $config->guestGroup) {
                return $id;
            }
        }
        return 0;
    }

    /** @return array<int, array{name: string, active: bool}> */
    private static function read(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }
        $groups = [];
        foreach (Db::select('SELECT `group_id`, `name`, `status` FROM `auth_group`') as $row) {
            $groups[(int)$row['group_id']] = ['name' => (string)$row['name'], 'active' => $row['status'] === 'active'];
        }
        return self::$groups = $groups;
    }
}
