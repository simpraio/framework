<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\Map;
use core\http\RouteSegment;
use RuntimeException;

final readonly class Route
{
    public function __construct(
        public string $defaultModule,
        public string $defaultController,
        public bool $aliasesEnabled = false,
        public int $aliasesCacheTtl = 3600,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $aliases = Map::section($raw, 'aliases');

        return new self(
            defaultModule: self::segment($raw, 'default_module', 'main'),
            defaultController: self::segment($raw, 'default_controller', 'info'),
            aliasesEnabled: Cast::bool($aliases['enabled'] ?? null, 'route.aliases.enabled', false),
            aliasesCacheTtl: max(0, Cast::int($aliases['cache_ttl'] ?? null, 'route.aliases.cache_ttl', 3600)),
        );
    }

    /**
     * Spelled as a URL segment, so / and /info resolve to one path id.
     *
     * @param array<string, mixed> $raw
     */
    private static function segment(array $raw, string $key, string $default): string
    {
        $value = Cast::string($raw[$key] ?? null, "route.{$key}", $default);

        return RouteSegment::normalize($value)
            ?? throw new RuntimeException("Config: 'route.{$key}' must be a URL segment - letters, digits and inner hyphens - got '{$value}'");
    }
}
