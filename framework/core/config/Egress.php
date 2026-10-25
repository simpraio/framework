<?php

declare(strict_types=1);

namespace core\config;

use core\http\Url;
use core\tools\Silently;

/**
 * When enabled, deny non-allowlisted hosts; address checking requires every resolved IP to be global.
 * Transports must pin the returned addresses rather than resolve the host again.
 */
final readonly class Egress
{
    /**
     * DNS freshness limit in seconds; connection pinning, not this TTL, prevents rebinding.
     */
    private const int RESOLVE_CACHE_TTL = 5;

    /** @param list<string> $allowlist lowercased exact hosts */
    public function __construct(
        public bool $enabled,
        public array $allowlist,
        public bool $blockPrivateIps,
    ) {
    }

    /** @param array<string, mixed> $egress */
    public static function fromArray(array $egress): self
    {
        return new self(
            enabled: Cast::bool($egress['enabled'] ?? null, 'egress.enabled', true),
            allowlist: Map::lowerStringList($egress, 'allowlist'),
            blockPrivateIps: Cast::bool($egress['block_private_ips'] ?? null, 'egress.block_private_ips', true),
        );
    }

    public function allows(string $url): bool
    {
        return $this->resolvedFor($url) !== null;
    }

    /**
     * Null denies; an empty list needs no pin (IP literal, disabled guard, or disabled address check).
     *
     * @return list<string>|null
     */
    public function resolvedFor(string $url): ?array
    {
        if (!$this->enabled) {
            return [];
        }

        $host = Url::parse($url)?->host;
        if ($host === null || !in_array($host, $this->allowlist, strict: true)) {
            return null;
        }

        if (!$this->blockPrivateIps) {
            return [];
        }

        $literal = trim($host, characters: '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($literal) ? [] : null;
        }

        $ips = self::resolveAll($host);

        // An unresolved host cannot be validated; never delegate an unchecked lookup to transport.
        if ($ips === []) {
            return null;
        }

        return array_all($ips, self::isPublicIp(...)) ? $ips : null;
    }

    /** @return list<string> */
    private static function resolveAll(string $host): array
    {
        /** @var array<string, array{0: int, 1: list<string>}> $cache */
        static $cache = [];
        $now = time();
        $cached = $cache[$host] ?? null;
        if ($cached !== null && $cached[0] > $now) {
            return $cached[1];
        }

        $resolved = gethostbynamel($host);
        $ips = is_array($resolved) ? $resolved : [];

        $records = Silently::call(static fn(): array|false => dns_get_record($host, DNS_AAAA));
        /** @var list<array<string, mixed>> $aaaa */
        $aaaa = array_values(array_filter(
            is_array($records) ? $records : [],
            static fn(mixed $record): bool => is_array($record),
        ));
        foreach ($aaaa as $record) {
            if (!array_key_exists('ipv6', $record) || !is_string($record['ipv6'])) {
                continue;
            }

            $ips[] = $record['ipv6'];
        }

        $ips = array_values($ips);
        $cache[$host] = [$now + self::RESOLVE_CACHE_TTL, $ips];
        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        return !IpRange::isNonGlobal($ip);
    }

}
