<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use RuntimeException;

/**
 * TLS is requested when a CA or client certificate/key pair is configured.
 */
final readonly class DatabaseSsl
{
    public function __construct(
        public string $ca = '',
        public string $cert = '',
        public string $key = '',
        public bool $verifyServerCert = true,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $cert = Cast::string($raw['cert'] ?? null, 'database.ssl.cert');
        $key = Cast::string($raw['key'] ?? null, 'database.ssl.key');

        if (($cert === '') !== ($key === '')) {
            throw new RuntimeException('Config: database.ssl.cert and database.ssl.key must be set together.');
        }

        return new self(
            ca: Cast::string($raw['ca'] ?? null, 'database.ssl.ca'),
            cert: $cert,
            key: $key,
            verifyServerCert: Cast::bool(
                $raw['verify_server_cert'] ?? null,
                'database.ssl.verify_server_cert',
                true,
            ),
        );
    }

    public function enabled(): bool
    {
        return $this->ca !== '' || $this->cert !== '';
    }
}
