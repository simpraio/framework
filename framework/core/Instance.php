<?php

declare(strict_types=1);

namespace core;

use core\tools\Identifier;

final class Instance
{
    private static string $prefix = '';

    public static function init(string $basePath): void
    {
        self::$prefix = Identifier::fastHash(rtrim($basePath, characters: '/\\')) . ':';
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }
}
