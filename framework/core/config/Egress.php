<?php

declare(strict_types=1);

namespace core\config;

/**
 * Outbound egress (SSRF) policy for transport extensions.
 *
 * When enabled, only exact allowlisted hosts are reachable. An empty allowlist
 * denies everything. When blockPrivateIps is enabled, an allowlisted host that
 * resolves to a private/reserved IP is rejected as defense in depth.
 */
final readonly class Egress
{
    /** Per-process DNS cache TTL (seconds). Short enough not to widen the rebind window. */
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
        if (!$this->enabled) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        $host = strtolower($host);

        if (!in_array($host, $this->allowlist, strict: true)) {
            return false;
        }

        return !$this->blockPrivateIps || $this->resolvesWithoutPrivateIp($host);
    }

    private function resolvesWithoutPrivateIp(string $host): bool
    {
        $literal = trim($host, characters: '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($literal);
        }

        $ips = self::resolveAll($host);
        if ($ips === []) {
            return true;
        }

        return array_all($ips, self::isPublicIp(...));
    }

    /** @return list<string> */
    private static function resolveAll(string $host): array
    {
        // Short per-process DNS cache; TTL limits stale results for rebinding-sensitive checks.
        // host => [expires_at, ips]
        /** @var array<string, array{0: int, 1: list<string>}> $cache */
        static $cache = [];
        $now = time();
        $cached = $cache[$host] ?? null;
        if ($cached !== null && $cached[0] > $now) {
            return $cached[1];
        }

        $resolved = gethostbynamel($host);
        $ips = is_array($resolved) ? $resolved : [];

        // dns_get_record() warns on a failed lookup, and the framework promotes warnings
        // to exceptions; suppress just this call and treat failure as "no records".
        set_error_handler(static fn(): bool => true);
        $records = dns_get_record($host, DNS_AAAA);
        restore_error_handler();
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
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
