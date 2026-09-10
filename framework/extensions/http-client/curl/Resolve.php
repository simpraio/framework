<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

/**
 * Pins a connection to the addresses the egress guard already validated.
 *
 * Without this, the guard resolves a name, approves what it saw, and then hands the NAME to curl,
 * which resolves it again independently. The two answers need not agree, so a host whose DNS is
 * hostile or merely changes between the two lookups is checked at one address and dialled at
 * another. Pinning removes the second lookup.
 *
 * This holds for direct connections only. A proxy resolves the destination itself and an entry here
 * does not reach that lookup, which is why a configured proxy is refused while the egress guard is
 * enabled with address enforcement - see {@see \extensions\http_client\Config::fromArray}.
 */
final class Resolve
{
    /**
     * A CURLOPT_RESOLVE entry for this URL's host and port.
     *
     * The port must match the one curl will dial or libcurl ignores the entry and resolves the name
     * normally, so an absent port is filled in from the scheme, matched case-insensitively. IPv6
     * literals are bracketed so their colons are not read as further host:port fields.
     *
     * @param non-empty-list<string> $addresses
     */
    public static function entry(string $url, array $addresses): string
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);

        if (!is_int($port)) {
            // parse_url keeps the scheme's case, so "HTTP://" would miss a case-sensitive test and
            // pin port 443 while curl dials 80. libcurl ignores an entry whose port does not match,
            // silently, which would leave the name resolved a second time and the pin unused.
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            $port = $scheme === 'http' ? 80 : 443;
        }

        $pins = array_map(
            static fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address,
            $addresses,
        );

        return $host . ':' . $port . ':' . implode(',', $pins);
    }
}
