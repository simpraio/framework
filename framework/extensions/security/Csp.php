<?php

declare(strict_types=1);

namespace extensions\security;

final class Csp
{
    public const string NONCE = '_csp_nonce';
    public const string NONCE_PLACEHOLDER = '{' . self::NONCE . '}';

    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= bin2hex(random_bytes(16));
    }

    public static function injectNonce(string $value): string
    {
        return str_replace(self::NONCE_PLACEHOLDER, self::nonce(), $value);
    }

    public static function usesNonce(string $value): bool
    {
        return str_contains($value, self::NONCE_PLACEHOLDER);
    }
}
