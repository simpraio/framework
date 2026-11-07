<?php

declare(strict_types=1);

namespace extensions\ratelimit;

use core\config\Cast;
use core\config\Config as CoreConfig;
use InvalidArgumentException;

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
        $max = Cast::int($raw['max'] ?? null, 'extensions.ratelimit.max', 60);
        if ($max < 1) {
            throw new InvalidArgumentException('extensions.ratelimit.max must be at least 1.');
        }

        // A window of 0 stores a counter that never expires.
        $window = Cast::int($raw['window'] ?? null, 'extensions.ratelimit.window', 60);
        if ($window < 1) {
            throw new InvalidArgumentException('extensions.ratelimit.window must be at least 1 second.');
        }

        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.ratelimit.enabled', false),
            max:     $max,
            window:  $window,
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
