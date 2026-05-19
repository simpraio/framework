<?php

declare(strict_types=1);

namespace core\config;

use RuntimeException;

/**
 * Strict casts for config values. Fails loudly on invalid input
 * instead of silently coercing (e.g. "abc" -> 0).
 */
final class Cast
{
    public static function int(mixed $value, string $field, int $default = 0): int
    {
        if ($value === null) {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }
        throw new RuntimeException("Config: '{$field}' must be an integer, got " . get_debug_type($value));
    }

    public static function bool(mixed $value, string $field, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $coerced = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($coerced === null) {
            throw new RuntimeException("Config: '{$field}' must be a boolean, got " . get_debug_type($value));
        }
        return $coerced;
    }

    public static function string(mixed $value, string $field, string $default = ''): string
    {
        if ($value === null) {
            return $default;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        throw new RuntimeException("Config: '{$field}' must be a string, got " . get_debug_type($value));
    }
}
