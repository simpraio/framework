<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

final class Encoding
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), from: '+/', to: '-_'), characters: '=');
    }

    public static function decode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, from: '-_', to: '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }

    public static function random(int $bytes = 32): string
    {
        return self::encode(random_bytes($bytes));
    }
}
