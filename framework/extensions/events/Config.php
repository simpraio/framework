<?php

declare(strict_types=1);

namespace extensions\events;

use core\config\Cast;
use core\config\Config as CoreConfig;

final readonly class Config
{
    public const string NAME = 'events';
    public const string DISABLED = 'EVENTS_DISABLED';

    public function __construct(
        public bool $enabled,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.events.enabled', true),
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
