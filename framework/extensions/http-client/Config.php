<?php

declare(strict_types=1);

namespace extensions\http_client;

use core\config\Cast;
use core\config\Egress;
use core\config\Map;
use RuntimeException;

final readonly class Config
{
    public const string NAME = 'http-client';

    /**
     * Explicit operator acknowledgment required before verify_tls=false is accepted.
     */
    public const string TLS_INSECURE_ACK = 'I_ACCEPT_INSECURE_TLS';

    private const array DEFAULT_PROTOCOLS = ['http', 'https'];

    /** @param list<string> $allowedProtocols */
    public function __construct(
        public bool $enabled,
        public int $retries,
        public int $retryDelay,
        public int $timeout,
        public int $connectTimeout,
        public bool $verifyTls,
        public int $maxResponseBytes,
        public ?string $proxy,
        public ?string $cookieJarDir,
        public array $allowedProtocols,
        public Egress $egress,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        /** @var mixed $cookieJarDir */
        $cookieJarDir = $raw['cookie_jar_dir'] ?? null;
        $cookieJarDir = is_string($cookieJarDir) && $cookieJarDir !== '' ? $cookieJarDir : null;

        // Operator-only outbound proxy. Never sourced from per-request options (egress bypass).
        /** @var mixed $proxy */
        $proxy = $raw['proxy'] ?? null;
        $proxy = is_string($proxy) && trim($proxy) !== '' ? trim($proxy) : null;

        $verifyTls = Cast::bool($raw['verify_tls'] ?? null, 'extensions.http-client.verify_tls', true);
        if (
            !$verifyTls
            && Cast::trimmedString($raw['tls_insecure_acknowledged'] ?? null, 'extensions.http-client.tls_insecure_acknowledged') !== self::TLS_INSECURE_ACK
        ) {
            throw new RuntimeException(
                'extensions.http-client.verify_tls=false disables TLS peer AND host verification for '
                . 'every outbound HTTP request (MITM-able). It is refused unless you ALSO set '
                . 'extensions.http-client.tls_insecure_acknowledged="' . self::TLS_INSECURE_ACK . '". '
                . 'Never do this in production — provide a CA bundle for self-signed/internal hosts instead.'
            );
        }

        $enabled = Cast::bool($raw['enabled'] ?? null, 'extensions.http-client.enabled', true);
        $egress = Egress::fromArray(Map::section($raw, 'egress'));

        // A proxy resolves the destination itself, so the addresses this application validated are
        // never the ones dialled, and CURLOPT_RESOLVE does not reach proxy-side resolution. Leaving
        // both on would advertise an SSRF address guarantee that cannot hold. Only when the guard
        // is enabled, and the client itself is: an extension that does not run enforces nothing,
        // so demanding a setting there would change nothing.
        if ($enabled && $proxy !== null && $egress->enabled && $egress->blockPrivateIps) {
            throw new RuntimeException(
                'extensions.http-client.proxy is set while extensions.http-client.egress.'
                . 'block_private_ips is enabled. The proxy resolves the destination hostname itself, '
                . 'so the addresses validated here are not the ones connected to and the private-IP '
                . 'check cannot be enforced. Set egress.block_private_ips=false to state that the '
                . 'trusted proxy owns that policy, or remove the proxy to keep enforcement here.'
            );
        }

        return new self(
            enabled: $enabled,
            retries: max(0, Cast::int($raw['retries'] ?? null, 'extensions.http-client.retries', 5)),
            retryDelay: max(0, Cast::int($raw['retry_delay'] ?? null, 'extensions.http-client.retry_delay', 1)),
            timeout: max(1, Cast::int($raw['timeout'] ?? null, 'extensions.http-client.timeout', 10)),
            connectTimeout: max(1, Cast::int($raw['connect_timeout'] ?? null, 'extensions.http-client.connect_timeout', 10)),
            verifyTls: $verifyTls,
            maxResponseBytes: max(1, Cast::int($raw['max_response_bytes'] ?? null, 'extensions.http-client.max_response_bytes', 10_485_760)),
            proxy: $proxy,
            cookieJarDir: $cookieJarDir,
            allowedProtocols: self::filterProtocols($raw['allowed_protocols'] ?? null),
            egress: $egress,
        );
    }

    /** @return list<string> */
    private static function filterProtocols(mixed $value): array
    {
        if (!is_array($value)) {
            return self::DEFAULT_PROTOCOLS;
        }

        $allowed = array_values(
            array_filter(
                array_unique(
                    array_map(
                        static fn(mixed $protocol): string => strtolower(trim(
                            Cast::string($protocol, 'extensions.http-client.allowed_protocols[]'),
                        )),
                        $value,
                    )
                ),
                static fn(string $protocol): bool => in_array($protocol, self::DEFAULT_PROTOCOLS, strict: true),
            )
        );

        return $allowed !== [] ? $allowed : self::DEFAULT_PROTOCOLS;
    }
}
