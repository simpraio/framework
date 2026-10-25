<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

use core\http\Url;
use InvalidArgumentException;

/**
 * Validated-address pins prevent a second DNS lookup on direct connections, not through proxies.
 */
final class Resolve
{
    /**
     * The port must match the connection or libcurl silently ignores the pin.
     *
     * @param non-empty-list<string> $addresses
     */
    public static function entry(string $url, array $addresses): string
    {
        // Egress validated this URL before handing out addresses; failing here means it was not.
        $parsed = Url::parse($url) ?? throw new InvalidArgumentException('Cannot pin addresses for an unparseable URL');

        $pins = array_map(
            static fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address,
            $addresses,
        );

        return $parsed->host . ':' . $parsed->port . ':' . implode(',', $pins);
    }
}
