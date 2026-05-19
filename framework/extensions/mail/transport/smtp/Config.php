<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

use core\config\Cast;
use core\config\Map;
use RuntimeException;
use SensitiveParameter;

final readonly class Config
{
    public function __construct(
        public string $host,
        public int $port,
        public SmtpEncryption $encryption,
        public bool $auth,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public int $timeout,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $smtp = Map::section($raw, 'smtp');
        $host = trim(Cast::string($smtp['host'] ?? null, 'extensions.mail.smtp.host'));

        if ($host === '') {
            throw new RuntimeException('SMTP_HOST_MISSING');
        }

        return new self(
            host: $host,
            port: max(1, Cast::int($smtp['port'] ?? null, 'extensions.mail.smtp.port', 587)),
            encryption: SmtpEncryption::tryFrom(
                strtolower(trim(Cast::string($smtp['encryption'] ?? null, 'extensions.mail.smtp.encryption', 'tls')))
            ) ?? SmtpEncryption::Tls,
            auth: Cast::bool($smtp['auth'] ?? null, 'extensions.mail.smtp.auth', true),
            username: trim(Cast::string($smtp['username'] ?? null, 'extensions.mail.smtp.username')),
            password: trim(Cast::string($smtp['password'] ?? null, 'extensions.mail.smtp.password')),
            timeout: max(1, Cast::int($smtp['timeout'] ?? null, 'extensions.mail.smtp.timeout', 30)),
        );
    }

    public function address(): string
    {
        return ($this->encryption === SmtpEncryption::Ssl ? 'ssl://' : '') . $this->host . ':' . $this->port;
    }
}
