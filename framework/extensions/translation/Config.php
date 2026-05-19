<?php

declare(strict_types=1);

namespace extensions\translation;

use core\config\Cast;
use core\config\Config as CoreConfig;

final readonly class Config
{
    public const string NAME = 'translation';
    public const string DISABLED = 'TRANSLATION_DISABLED';

    public function __construct(
        public bool $enabled,
        public int $cacheTtl,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.translation.enabled', true),
            cacheTtl: max(0, Cast::int($raw['cache_ttl'] ?? null, 'extensions.translation.cache_ttl', 3600)),
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
