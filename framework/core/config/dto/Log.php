<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\Map;

final readonly class Log
{
    /** @param list<string> $redactKeys */
    public function __construct(
        public string $level,
        public bool $rotateDaily,
        public int $retentionDays,
        public array $redactKeys,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            level: Cast::string($raw['level'] ?? null, 'log.level', 'warning'),
            rotateDaily: Cast::bool($raw['rotate_daily'] ?? null, 'log.rotate_daily', true),
            retentionDays: Cast::int($raw['retention_days'] ?? null, 'log.retention_days', 14),
            redactKeys: Map::lowerStringList($raw, 'redact_keys'),
        );
    }
}
