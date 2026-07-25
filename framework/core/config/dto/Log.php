<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\Map;

final readonly class Log
{
    /**
     * Accepted log levels, in the spelling core\log\Writer indexes by. Listed here rather than
     * imported so the config layer stays independent of the logger it configures; the Writer
     * tolerates an unknown level, so the two can never wedge each other.
     *
     * @var non-empty-list<string>
     */
    private const array LEVELS = ['debug', 'info', 'warning', 'error'];

    /** @param list<string> $redactKeys */
    public function __construct(
        public string $level,
        public bool $rotateDaily,
        public int $retentionDays,
        public array $redactKeys,
        public bool $redactSecrets,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            level: Cast::oneOf($raw['level'] ?? null, 'log.level', self::LEVELS, 'warning'),
            rotateDaily: Cast::bool($raw['rotate_daily'] ?? null, 'log.rotate_daily', true),
            retentionDays: Cast::int($raw['retention_days'] ?? null, 'log.retention_days', 14),
            redactKeys: Map::lowerStringList($raw, 'redact_keys'),
            redactSecrets: Cast::bool($raw['redact_secrets'] ?? null, 'log.redact_secrets', true),
        );
    }
}
