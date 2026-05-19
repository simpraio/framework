<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;

final readonly class Project
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        public string $name,
        public string $timezone,
        public string $url,
        public array $allowedHosts,
        public bool $debug,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $hosts = is_array($raw['allowed_hosts'] ?? null) ? array_values($raw['allowed_hosts']) : [];

        return new self(
            name: Cast::string($raw['name'] ?? null, 'project.name'),
            timezone: Cast::string($raw['timezone'] ?? null, 'project.timezone', 'UTC'),
            url: Cast::string($raw['url'] ?? null, 'project.url', ''),
            allowedHosts: array_map(static fn(mixed $h): string => strtolower(Cast::string($h, 'project.allowed_hosts[]')), $hosts),
            debug: Cast::bool($raw['debug'] ?? null, 'project.debug'),
        );
    }
}
