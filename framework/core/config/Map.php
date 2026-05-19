<?php

declare(strict_types=1);

namespace core\config;

final class Map
{
    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function section(array $raw, string $key): array
    {
        /** @var mixed $value */
        $value = $raw[$key] ?? null;

        return is_array($value) ? self::stringKeyed($value) : [];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function stringMap(array $raw, string $key): array
    {
        /** @var mixed $value */
        $value = $raw[$key] ?? null;
        return self::strings($value);
    }

    /** @return array<string, string> */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach (array_keys($value) as $name) {
            self::copyString($map, $name, $value[$name]);
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    public static function stringList(array $raw, string $key): array
    {
        /** @var mixed $value */
        $value = $raw[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach (array_keys($value) as $index) {
            self::addString($list, $value[$index]);
        }
        return array_values(array_unique($list));
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    public static function lowerStringList(array $raw, string $key): array
    {
        return array_values(array_unique(array_map(strtolower(...), self::stringList($raw, $key))));
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $map = [];
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                continue;
            }
            $map[$key] = $value[$key];
        }
        return $map;
    }

    /** @param array<string, string> $map */
    private static function copyString(array &$map, int|string $key, mixed $value): void
    {
        if (is_string($key) && is_string($value)) {
            $map[$key] = $value;
        }
    }

    /** @param list<string> $list */
    private static function addString(array &$list, mixed $value): void
    {
        if (is_string($value) && $value !== '') {
            $list[] = $value;
        }
    }
}
