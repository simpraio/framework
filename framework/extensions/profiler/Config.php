<?php

declare(strict_types=1);

namespace extensions\profiler;

use core\config\Cast;
use core\config\Config as CoreConfig;

final readonly class Config
{
    public const string NAME = 'profiler';
    public const string DISABLED = 'PROFILER_DISABLED';

    public function __construct(
        public bool $enabled,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.profiler.enabled', false),
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
