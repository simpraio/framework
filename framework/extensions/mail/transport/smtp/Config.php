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

    /** Labels that are all digits read as an IPv4 address, which RFC 5321 accepts only as a bracketed literal. */
    private const string HOSTNAME = '/^(?=.{1,253}$)(?![0-9.]+$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D';

    public function __construct(
        public string $host,
        public int $port,
        public SmtpEncryption $encryption,
        public bool $auth,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public int $timeout,
        public string $ehloHost = '',
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

        $ehloHost = Cast::trimmedString($smtp['ehlo_host'] ?? null, 'extensions.mail.smtp.ehlo_host');

        if ($ehloHost !== '' && !self::isDomain($ehloHost)) {
            throw new RuntimeException(
                "Config: 'extensions.mail.smtp.ehlo_host' must be a hostname or a bracketed address literal.",
            );
        }

        return new self(
            host: $host,
            port: max(1, Cast::int($smtp['port'] ?? null, 'extensions.mail.smtp.port', 587)),
            encryption: self::encryption($smtp['encryption'] ?? null),
            auth: Cast::bool($smtp['auth'] ?? null, 'extensions.mail.smtp.auth', true),
            username: Cast::trimmedString($smtp['username'] ?? null, 'extensions.mail.smtp.username'),
            password: Cast::string($smtp['password'] ?? null, 'extensions.mail.smtp.password'),
            timeout: max(1, Cast::int($smtp['timeout'] ?? null, 'extensions.mail.smtp.timeout', 30)),
            ehloHost: $ehloHost,
        );
    }

    /** A misspelt value is refused: falling back to a mode would discard what the operator meant. */
    private static function encryption(mixed $value): SmtpEncryption
    {
        $field = 'extensions.mail.smtp.encryption';
        $allowed = array_map(static fn(SmtpEncryption $case): string => $case->value, SmtpEncryption::cases());

        return SmtpEncryption::from(Cast::oneOf(Cast::trimmedString($value, $field, 'tls'), $field, $allowed, 'tls'));
    }

    public function address(): string
    {
        return ($this->encryption === SmtpEncryption::Ssl ? 'ssl://' : '') . $this->host . ':' . $this->port;
    }

    /** The name EHLO and HELO announce: the configured one, else the machine name when a relay can read it. */
    public function resolvedEhloHost(): string
    {
        if ($this->ehloHost !== '') {
            return $this->ehloHost;
        }

        $machine = gethostname();

        return is_string($machine) && preg_match(self::HOSTNAME, $machine) === 1 ? $machine : 'localhost';
    }

    private static function isDomain(string $value): bool
    {
        if (preg_match(self::HOSTNAME, $value) === 1) {
            return true;
        }

        $match = [];
        if (preg_match('/^\[(IPv6:)?([0-9A-Fa-f:.]+)\]$/Di', $value, $match) !== 1) {
            return false;
        }

        return filter_var($match[2], FILTER_VALIDATE_IP, $match[1] === '' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) !== false;
    }
}
