<?php

declare(strict_types=1);

namespace core\config;

/**
 * Outbound egress (SSRF) policy for transport extensions.
 *
 * When enabled, only exact allowlisted hosts are reachable. An empty allowlist
 * denies everything. When blockPrivateIps is enabled, an allowlisted host that
 * resolves to a non-globally-routable IP is rejected as defense in depth - wider than the private
 * and reserved ranges, since shared address space (100.64.0.0/10) and the benchmarking and
 * documentation ranges are refused too. Being special-purpose is not the test: the ranges RFC 6890
 * marks globally reachable, such as the NAT64 prefix 64:ff9b::/96, stay allowed. A host that does not
 * resolve at all is rejected too. {@see resolvedFor} returns the validated addresses so the
 * transport can connect to those rather than resolve the name a second time.
 */
final readonly class Egress
{
    /**
     * Per-process DNS cache TTL (seconds).
     *
     * Not a rebinding control. The addresses returned here are pinned onto the connection, so a
     * request reaches an address that was validated whether it came from this cache or a fresh
     * lookup. What the TTL bounds is freshness: how long a retry or a later request keeps using
     * addresses that may have moved, and how soon a failover is picked up.
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
     * The validated addresses a connection to $url must be pinned to, or null when it is denied.
     *
     * Returning them is what closes the rebinding window: validating a name and then letting the
     * transport resolve it again checks one answer and connects to another. The caller must hand
     * these to the connection instead of the name. An empty list means there is nothing to pin -
     * the host is already an IP literal, or address checking is off - not that anything goes.
     *
     * @return list<string>|null
     */
    public function resolvedFor(string $url): ?array
    {
        if (!$this->enabled) {
            return [];
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        $host = strtolower($host);

        if (!in_array($host, $this->allowlist, strict: true)) {
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

        // Fail closed on an empty answer. Treating "did not resolve" as allowed handed the name
        // straight to the transport, whose resolver may differ from this one - a host reachable
        // only over AAAA when the AAAA lookup here failed, for instance - and the address check
        // was then never applied to what was actually dialed.
        if ($ips === []) {
            return null;
        }

        return array_all($ips, self::isPublicIp(...)) ? $ips : null;
    }

    /** @return list<string> */
    private static function resolveAll(string $host): array
    {
        // Per-process cache. A retry inside the TTL reuses these addresses instead of resolving
        // again; they were validated when first seen and are pinned onto the connection, so the
        // TTL governs freshness and failover rather than the rebinding window.
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
        // IpRange owns the whole decision, its own backstop included. Re-applying the filter flags
        // here would undo the table: they refuse addresses IANA marks reachable, such as the PCP
        // anycast address inside the otherwise non-global 192.0.0.0/24.
        return !IpRange::isNonGlobal($ip);
    }

}
