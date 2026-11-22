<?php

declare(strict_types=1);

namespace core\config;

use RuntimeException;

final class Map
{
    /**
     * An empty array cannot express whether a settings map or a list was intended; the caller decides.
     *
     * @phpstan-assert-if-true non-empty-array<array-key, mixed> $value
     */
    public static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && !array_is_list($value);
    }

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
     * Absent or null is the empty map; any other value that is not a map of strings is an error, never a
     * map with entries dropped: a header written as false would otherwise just not be sent.
     *
     * @param array<string, mixed> $raw
     * @param string $field the key path a refusal names
     * @return array<string, string>
     * @throws RuntimeException
     */
    public static function stringMap(array $raw, string $key, string $field): array
    {
        /** @var mixed $value */
        $value = $raw[$key] ?? [];
        $entries = is_array($value)
            ? $value
            : throw new RuntimeException("Config: '{$field}' must be a map of strings keyed by name, got " . get_debug_type($value) . '.');

        $map = [];
        foreach (array_keys($entries) as $name) {
            $entryName = is_string($name)
                ? $name
                : throw new RuntimeException("Config: '{$field}' must be a map keyed by name, got an entry with a numeric key.");
            $map[$entryName] = is_string($entries[$name])
                ? $entries[$name]
                : throw new RuntimeException("Config: '{$field}.{$name}' must be a string, got " . get_debug_type($entries[$name]) . '.');
        }
        return $map;
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
     * Absent or null is the empty list; any other value that is not an array of strings is an error, never a
     * shorter list: a redaction key or an allowlist entry dropped here is dropped unseen. Entries are
     * trimmed, since padding makes one that never matches, and a blank one is dropped.
     *
     * @param array<string, mixed> $raw
     * @param string $field the key path a refusal names
     * @return list<string>
     * @throws RuntimeException
     */
    public static function stringList(array $raw, string $key, string $field): array
    {
        /** @var mixed $value */
        $value = $raw[$key] ?? [];
        $entries = is_array($value)
            ? $value
            : throw new RuntimeException("Config: '{$field}' must be a list of strings, got " . get_debug_type($value) . '.');

        $trimmed = array_map(
            static fn(mixed $entry): string => is_string($entry)
                ? trim($entry)
                : throw new RuntimeException("Config: '{$field}' must hold strings only, got " . get_debug_type($entry) . ' in an entry.'),
            $entries,
        );

        return array_values(array_unique(array_filter($trimmed, static fn(string $entry): bool => $entry !== '')));
    }

    /**
     * @param array<string, mixed> $raw
     * @param string $field the key path a refusal names
     * @return list<string>
     * @throws RuntimeException
     */
    public static function lowerStringList(array $raw, string $key, string $field): array
    {
        return array_values(array_unique(array_map(strtolower(...), self::stringList($raw, $key, $field))));
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
