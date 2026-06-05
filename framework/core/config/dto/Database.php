<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\Map;
use core\config\RedactsSecrets;
use JsonSerializable;
use SensitiveParameter;

final readonly class Database implements JsonSerializable
{
    use RedactsSecrets;

    /** @return list<string> */
    protected function secretKeys(): array
    {
        return ['password'];
    }

    /** @param array<int|string, mixed> $options */
    public function __construct(
        public string $driver,
        public string $hostname,
        public int $port,
        public string $database,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public string $charset,
        public string $timezone,
        public array $options,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw, string $timezone): self
    {
        $options = Map::section($raw, 'options');

        return new self(
            driver: Cast::string($raw['driver'] ?? null, 'database.driver'),
            hostname: Cast::string($raw['hostname'] ?? null, 'database.hostname'),
            port: Cast::int($raw['port'] ?? null, 'database.port'),
            database: Cast::string($raw['database'] ?? null, 'database.database'),
            username: Cast::string($raw['username'] ?? null, 'database.username'),
            password: Cast::string($raw['password'] ?? null, 'database.password'),
            charset: Cast::string($raw['charset'] ?? null, 'database.charset', 'utf8mb4'),
            timezone: $timezone !== '' ? $timezone : 'UTC',
            options: $options,
        );
    }
}
