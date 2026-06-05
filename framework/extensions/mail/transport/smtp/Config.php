<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

use core\config\Cast;
use core\config\Map;
use core\config\RedactsSecrets;
use JsonSerializable;
use RuntimeException;
use SensitiveParameter;

final readonly class Config implements JsonSerializable
{
    use RedactsSecrets;

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

    /** @return list<string> */
    protected function secretKeys(): array
    {
        return ['password'];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $smtp = Map::section($raw, 'smtp');
        $host = Cast::trimmedString($smtp['host'] ?? null, 'extensions.mail.smtp.host');

        if ($host === '') {
            throw new RuntimeException('SMTP_HOST_MISSING');
        }

        return new self(
            host: $host,
            port: max(1, Cast::int($smtp['port'] ?? null, 'extensions.mail.smtp.port', 587)),
            encryption: SmtpEncryption::tryFrom(
                strtolower(Cast::trimmedString($smtp['encryption'] ?? null, 'extensions.mail.smtp.encryption', 'tls'))
            ) ?? SmtpEncryption::Tls,
            auth: Cast::bool($smtp['auth'] ?? null, 'extensions.mail.smtp.auth', true),
            username: Cast::trimmedString($smtp['username'] ?? null, 'extensions.mail.smtp.username'),
            password: Cast::trimmedString($smtp['password'] ?? null, 'extensions.mail.smtp.password'),
            timeout: max(1, Cast::int($smtp['timeout'] ?? null, 'extensions.mail.smtp.timeout', 30)),
        );
    }

    public function address(): string
    {
        return ($this->encryption === SmtpEncryption::Ssl ? 'ssl://' : '') . $this->host . ':' . $this->port;
    }
}
