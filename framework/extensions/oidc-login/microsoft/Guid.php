<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

final class Guid
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
