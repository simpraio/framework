<?php

declare(strict_types=1);

namespace extensions\httpclient;

use core\config\Cast;

final readonly class Config
{
    public const string NAME = 'httpclient';

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
        public ?string $cookieJarDir,
        public array $allowedProtocols,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        /** @var mixed $cookieJarDir */
        $cookieJarDir = $raw['cookie_jar_dir'] ?? null;
        $cookieJarDir = is_string($cookieJarDir) && $cookieJarDir !== '' ? $cookieJarDir : null;

        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.httpclient.enabled', true),
            retries: max(0, Cast::int($raw['retries'] ?? null, 'extensions.httpclient.retries', 2)),
            retryDelay: max(0, Cast::int($raw['retry_delay'] ?? null, 'extensions.httpclient.retry_delay', 0)),
            timeout: max(1, Cast::int($raw['timeout'] ?? null, 'extensions.httpclient.timeout', 10)),
            connectTimeout: max(1, Cast::int($raw['connect_timeout'] ?? null, 'extensions.httpclient.connect_timeout', 10)),
            verifyTls: Cast::bool($raw['verify_tls'] ?? null, 'extensions.httpclient.verify_tls', true),
            maxResponseBytes: max(1, Cast::int($raw['max_response_bytes'] ?? null, 'extensions.httpclient.max_response_bytes', 10_485_760)),
            cookieJarDir: $cookieJarDir,
            allowedProtocols: self::filterProtocols($raw['allowed_protocols'] ?? null),
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
                            Cast::string($protocol, 'extensions.httpclient.allowed_protocols[]'),
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
