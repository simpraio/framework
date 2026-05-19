<?php

declare(strict_types=1);

namespace extensions\security;

use core\config\Cast;
use core\config\Map;

final readonly class Config
{
    public const string NAME = 'security';

    /** @param array<string, string> $headers */
    public function __construct(
        public bool $enabled,
        public array $headers,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.security.enabled', true),
            headers: array_filter(Map::stringMap($raw, 'headers'), static fn(string $value): bool => $value !== ''),
        );
    }
}
