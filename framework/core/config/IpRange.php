<?php

declare(strict_types=1);

namespace core\config;

/**
 * Whether an address sits in a range IANA does not mark globally reachable.
 *
 * This is the egress guard's boundary, kept apart from the policy in {@see Egress} because it
 * answers a different question: not "may this host be reached" but "is this address routable on the
 * internet at all".
 *
 * The table below is the authority. PHP's `FILTER_FLAG_GLOBAL_RANGE` is documented as following
 * RFC 6890's global attribute but classifies from an incomplete list: on 8.4 and 8.5 it accepts
 * IPv4 and IPv6 multicast, `64:ff9b:1::/48`, `100:0:0:1::/64`, `3fff::/20` and `5f00::/16`. The
 * first of those matters most - RFC 6052 encodes `127.0.0.1` under that prefix as
 * `64:ff9b:1:7f00:0:100::`, splitting the address around the zero `u` octet. The flag is kept only
 * for addresses the table does not classify at all, where it can add a refusal but never an
 * allowance.
 */
final class IpRange
{
    /**
     * The IANA special-purpose registries, as `CIDR => globally reachable`.
     *
     * Matched longest-prefix-first, so a specific entry overrides the block containing it: PCP
     * anycast at `192.0.0.9/32` is reachable although `192.0.0.0/24` around it is not. Entries PHP
     * already refuses are listed anyway, so a change in its classification cannot reopen one.
     *
     * Sources: iana.org/assignments/iana-ipv4-special-registry and .../iana-ipv6-special-registry.
     */
    private const array CLASSIFICATION = [
        // ---- IPv4 ----
        // IPv4 is fully allocated, so an address outside these carve-outs is ordinary unicast.
        // IPv6 is not, and is handled the other way round below.
        '0.0.0.0/8' => false,           // "this network"
        '10.0.0.0/8' => false,          // private
        '100.64.0.0/10' => false,       // shared address space (CGNAT)
        '127.0.0.0/8' => false,         // loopback
        '169.254.0.0/16' => false,      // link-local, incl. cloud metadata at 169.254.169.254
        '172.16.0.0/12' => false,       // private
        '192.0.0.0/24' => false,        // IETF protocol assignments
        '192.0.0.9/32' => true,         // ...except PCP anycast (RFC 7723)
        '192.0.0.10/32' => true,        // ...and TURN anycast (RFC 8155)
        '192.0.2.0/24' => false,        // documentation (TEST-NET-1)
        '192.31.196.0/24' => true,      // AS112-v4
        '192.52.193.0/24' => true,      // AMT
        '192.88.99.0/24' => false,      // 6to4 relay anycast, deprecated by RFC 7526
        '192.168.0.0/16' => false,      // private
        '192.175.48.0/24' => true,      // direct delegation AS112
        '198.18.0.0/15' => false,       // benchmarking
        '198.51.100.0/24' => false,     // documentation (TEST-NET-2)
        '203.0.113.0/24' => false,      // documentation (TEST-NET-3)
        '224.0.0.0/4' => false,         // multicast
        '240.0.0.0/4' => false,         // reserved, incl. 255.255.255.255
        // ---- IPv6 ----
        // Only 2000::/3 is allocated as global unicast; every other top-level block is reserved by
        // the IETF, so IPv6 starts denied and the allocation opens it. Without that a reserved
        // range - 4000::1, fe00::1 - reads as ordinary unicast merely by being absent from the
        // special-purpose registry, which lists carve-outs rather than the allocation boundary.
        '::/0' => false,                // reserved unless an allocation below says otherwise
        '2000::/3' => true,             // global unicast
        '::/128' => false,              // unspecified
        '::1/128' => false,             // loopback
        '::/96' => false,               // IPv4-compatible, deprecated: ::7f00:1 is 127.0.0.1
        '::ffff:0:0/96' => false,       // IPv4-mapped: an IPv4 destination in IPv6 clothing
        '64:ff9b::/96' => true,         // NAT64 well-known prefix
        '64:ff9b:1::/48' => false,      // local-use translation
        '100::/64' => false,            // discard-only
        '100:0:0:1::/64' => false,      // dummy prefix
        '2001::/23' => false,           // IETF protocol assignments, incl. TEREDO at 2001::/32
        '2001:1::1/128' => true,        // ...except PCP
        '2001:1::2/128' => true,        // ...TURN
        '2001:1::3/128' => true,        // ...DNS-SD service registration
        '2001:2::/48' => false,         // benchmarking
        '2001:3::/32' => true,          // AMT
        '2001:4:112::/48' => true,      // AS112-v6
        '2001:10::/28' => false,        // ORCHID, deprecated
        '2001:20::/28' => true,         // ORCHIDv2
        '2001:30::/28' => true,         // drone remote ID
        '2001:db8::/32' => false,       // documentation
        '2002::/16' => false,           // 6to4, deprecated by RFC 7526
        '2620:4f:8000::/48' => true,    // direct delegation AS112
        '3fff::/20' => false,           // documentation (RFC 9637)
        '5f00::/16' => false,           // segment routing SIDs
        'fc00::/7' => false,            // unique local
        'fe80::/10' => false,           // link-local
        'fec0::/10' => false,           // site-local, deprecated by RFC 3879
        'ff00::/8' => false,            // multicast
    ];

    public static function isNonGlobal(string $ip): bool
    {
        // Anything that is not an address at all cannot be shown to be routable.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        $classified = self::classify($packed);
        if ($classified !== null) {
            return !$classified;
        }

        // Unclassified, which after the IPv4 carve-outs means ordinary unicast (IPv6 is always
        // classified, since ::/0 covers it). The filter flags run here only: they can withhold an
        // address the table does not cover, never permit one it denies.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) === false;
    }

    /**
     * The globally-reachable attribute of the most specific range containing the address, or null
     * when no range does.
     *
     * @param string $packed the address in inet_pton form
     */
    private static function classify(string $packed): ?bool
    {
        $bestBits = -1;
        $bestValue = null;

        foreach (self::CLASSIFICATION as $cidr => $global) {
            [$subnet, $prefix] = explode('/', $cidr);
            $bits = (int) $prefix;

            if ($bits <= $bestBits || !self::contains($subnet, $bits, $packed)) {
                continue;
            }

            $bestBits = $bits;
            $bestValue = $global;
        }

        return $bestValue;
    }

    /** @param string $packed the address in inet_pton form */
    private static function contains(string $subnet, int $bits, string $packed): bool
    {
        $subnetPacked = inet_pton($subnet);

        // An IPv4 address never matches an IPv6 range, and vice versa.
        if ($subnetPacked === false || strlen($subnetPacked) !== strlen($packed)) {
            return false;
        }

        $wholeBytes = intdiv(num1: $bits, num2: 8);
        if (
            $wholeBytes > 0
            && substr(string: $packed, offset: 0, length: $wholeBytes)
                !== substr(string: $subnetPacked, offset: 0, length: $wholeBytes)
        ) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        // Compare the partial byte through the mask, as integers: a string `&` would work but the
        // analyzer cannot type it, and the intent reads better this way.
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($subnetPacked[$wholeBytes]) & $mask);
    }
}
