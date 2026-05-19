<?php

declare(strict_types=1);

namespace extensions\seo;

use core\config\Cast;
use core\config\Config as CoreConfig;
use core\config\Map;

final readonly class Config
{
    public const string NAME = 'seo';
    public const string DISABLED = 'SEO_DISABLED';

    public function __construct(
        public bool $enabled,
        public int $cacheTtl,
        public string $title,
        public string $description,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $defaults = Map::section($raw, 'defaults');

        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.seo.enabled', true),
            cacheTtl: max(0, Cast::int($raw['cache_ttl'] ?? null, 'extensions.seo.cache_ttl', 3600)),
            title: Cast::string($defaults['title'] ?? null, 'extensions.seo.defaults.title'),
            description: Cast::string($defaults['description'] ?? null, 'extensions.seo.defaults.description'),
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
