<?php

declare(strict_types=1);

namespace extensions\registry;

final class Settings
{
    private const string GROUP = 'system';

    public static function get(string $key, ?string $default = null): ?string
    {
        return Store::get(self::GROUP, $key, Store::LANGUAGE_AGNOSTIC) ?? $default;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return Store::group(self::GROUP, Store::LANGUAGE_AGNOSTIC);
    }
}
