<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\Map;

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
            defaultModule: Cast::string($raw['default_module'] ?? null, 'route.default_module', 'main'),
            defaultController: Cast::string($raw['default_controller'] ?? null, 'route.default_controller', 'info'),
            aliasesEnabled: Cast::bool($aliases['enabled'] ?? null, 'route.aliases.enabled', false),
            aliasesCacheTtl: max(0, Cast::int($aliases['cache_ttl'] ?? null, 'route.aliases.cache_ttl', 3600)),
        );
    }
}
