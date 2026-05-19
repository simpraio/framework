<?php

declare(strict_types=1);

namespace extensions\errorlog;

use core\config\Cast;
use core\config\Config as CoreConfig;
use core\config\Map;

final readonly class Config
{
    public const string NAME = 'errorlog';
    public const string DISABLED = 'ERRORLOG_DISABLED';

    /** @param list<string> $redactKeys */
    public function __construct(
        public bool $enabled,
        public int $retentionDays,
        public bool $storeTrace,
        public array $redactKeys,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled:       Cast::bool($raw['enabled'] ?? null, 'extensions.errorlog.enabled', true),
            retentionDays: max(0, Cast::int($raw['retention_days'] ?? null, 'extensions.errorlog.retention_days', 30)),
            storeTrace:    Cast::bool($raw['store_trace'] ?? null, 'extensions.errorlog.store_trace', true),
            redactKeys:    Map::lowerStringList($raw, 'redact_keys'),
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }

    public static function ensureEnabled(self $config): void
    {
        CoreConfig::ensureEnabled($config, self::DISABLED);
    }
}
