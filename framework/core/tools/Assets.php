<?php

declare(strict_types=1);

namespace core\tools;

use core\cache\Cache;

final readonly class Assets
{
    private const int CACHE_TTL = 60;

    public function __construct(private string $publicDir)
    {
    }

    public function version(string $relativePath): string
    {
        $relative = '/' . ltrim(string: $relativePath, characters: '/');
        $file = $this->publicDir . $relative;

        return Cache::remember(
            'asset.version.' . Identifier::fastHash($relative),
            static fn(): string => is_file($file) ? (string) filemtime($file) : '1',
            self::CACHE_TTL,
        );
    }
}
