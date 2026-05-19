<?php

declare(strict_types=1);

namespace extensions\registry;

use InvalidArgumentException;

final class Registry
{
    private const array RESERVED_GROUPS = ['system'];

    public static function get(string $group, string $key, ?string $language = null): ?string
    {
        self::guard($group);
        return Store::get($group, $key, $language ?? Store::language());
    }

    /** @return array<string, string> */
    public static function group(string $group, ?string $language = null): array
    {
        self::guard($group);
        return Store::group($group, $language ?? Store::language());
    }

    private static function guard(string $group): void
    {
        if (in_array($group, self::RESERVED_GROUPS, strict: true)) {
            throw new InvalidArgumentException("Registry group '{$group}' is reserved; use Settings facade.");
        }
    }
}
