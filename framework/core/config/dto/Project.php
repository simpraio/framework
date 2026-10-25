<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\http\Url;
use RuntimeException;

final readonly class Project
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        public string $name,
        public string $timezone,
        public string $url,
        public array $allowedHosts,
        public bool $debug,
        public int $maxJsonDepth,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        // Only a missing key or [] switches the Host check off. Anything else that is not a list -
        // null included, which a local file can set over the default - is a mistake, and reading it
        // as [] would switch the check off without a word.
        /** @var mixed $hosts */
        $hosts = array_key_exists('allowed_hosts', $raw) ? $raw['allowed_hosts'] : [];
        if (!is_array($hosts)) {
            throw new RuntimeException(
                "Config: 'project.allowed_hosts' must be a list of host names, or [] to switch the check off; got " . get_debug_type($hosts),
            );
        }

        return new self(
            name: Cast::string($raw['name'] ?? null, 'project.name'),
            timezone: Cast::string($raw['timezone'] ?? null, 'project.timezone', 'UTC'),
            url: Cast::string($raw['url'] ?? null, 'project.url', ''),
            allowedHosts: array_map(self::allowedHost(...), array_values($hosts)),
            debug: Cast::bool($raw['debug'] ?? null, 'project.debug'),
            maxJsonDepth: max(1, Cast::int($raw['max_json_depth'] ?? null, 'project.max_json_depth', 64)),
        );
    }

    /** An entry is compared with the Host header's host part, so one that cannot equal it is refused. */
    private static function allowedHost(mixed $value): string
    {
        $host = strtolower(Cast::string($value, 'project.allowed_hosts[]'));
        if (Url::host($host) !== $host || str_contains($host, '*') || !mb_check_encoding($host, encoding: 'ASCII')) {
            throw new RuntimeException(
                "Config: 'project.allowed_hosts[]' must be a host name alone - no port, path or wildcard, and an IDN in its xn-- form - got '{$host}'",
            );
        }

        return $host;
    }
}
