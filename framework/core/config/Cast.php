<?php

declare(strict_types=1);

namespace core\config;

use core\tools\IntegerRange;
use RuntimeException;

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
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            if (!IntegerRange::fits($value)) {
                throw new RuntimeException("Config: '{$field}' must fit in a PHP integer, got " . $value);
            }
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
        // FILTER_VALIDATE_BOOLEAN accepts blank strings as false, not invalid input.
        if (is_string($value) && trim($value) === '') {
            throw new RuntimeException("Config: '{$field}' must be a boolean, got a blank string.");
        }
        $coerced = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($coerced === null) {
            throw new RuntimeException("Config: '{$field}' must be a boolean, got " . get_debug_type($value));
        }
        return $coerced;
    }

    /**
     * Match case-insensitively and return the configured canonical spelling.
     *
     * @param non-empty-list<string> $allowed canonical spellings
     */
    public static function oneOf(mixed $value, string $field, array $allowed, string $default): string
    {
        $candidate = self::string($value, $field, $default);

        foreach ($allowed as $option) {
            if (strcasecmp($candidate, $option) === 0) {
                return $option;
            }
        }

        throw new RuntimeException(
            "Config: '{$field}' must be one of " . implode(', ', $allowed) . ", got '{$candidate}'."
        );
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

    public static function trimmedString(mixed $value, string $field, string $default = ''): string
    {
        return trim(self::string($value, $field, $default));
    }
}
