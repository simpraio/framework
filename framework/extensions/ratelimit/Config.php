<?php

declare(strict_types=1);

namespace extensions\ratelimit;

use core\config\Cast;
use core\config\Config as CoreConfig;

final readonly class Config
{
    public const string NAME = 'ratelimit';
    public const string DISABLED = 'RATELIMIT_DISABLED';

    public function __construct(
        public bool $enabled,
        public int $max,
        public int $window,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.ratelimit.enabled', true),
            max:     Cast::int($raw['max'] ?? null,     'extensions.ratelimit.max',     60),
            window:  Cast::int($raw['window'] ?? null,  'extensions.ratelimit.window',  60),
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
