<?php

declare(strict_types=1);

namespace core\tools;

final class IntegerRange
{
    public static function fits(string $value): bool
    {
        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            return false;
        }

        // Compare digits before casting: PHP silently saturates an overflowing integer.
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, offset: 1) : $value, characters: '0');
        if ($digits === '') {
            return true;
        }

        $limit = $negative ? substr((string)PHP_INT_MIN, offset: 1) : (string)PHP_INT_MAX;
        return strlen($digits) < strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0);
    }
}
