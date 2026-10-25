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
            if (!is_string($name) || !is_string($value[$name])) {
                continue;
            }
            $map[$name] = $value[$name];
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
            if (!is_string($value[$index]) || $value[$index] === '') {
                continue;
            }
            $list[] = $value[$index];
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

    /** @param array<string, mixed> $config */
    public static function hasPath(array $config, string $path): bool
    {
        $value = $config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            /** @var mixed $value */
            $value = $value[$key];
        }
        return true;
    }
}
