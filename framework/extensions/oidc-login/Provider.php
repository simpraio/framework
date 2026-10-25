<?php

declare(strict_types=1);

namespace extensions\oidc_login;

use core\config\Cast;
use core\http\Url;
use extensions\oidc_login\google\Registration as GoogleRegistration;
use extensions\oidc_login\microsoft\Registration as MicrosoftRegistration;
use extensions\oidc_login\oidc\Registration;
use InvalidArgumentException;

final readonly class Provider
{
    /** @var array<string, array{reason: string, prefix: string, block: string}> */
    public const array NAMING = [
        'microsoft' => ['reason' => 'MS_LOGIN_', 'prefix' => 'MS', 'block' => 'Microsoft'],
        'google' => ['reason' => 'GOOGLE_LOGIN_', 'prefix' => 'GOOGLE', 'block' => 'Google'],
    ];

    private function __construct(
        public string $name,
        public bool $enabled,
        public Registration $registration,
        public string $sessionKey,
        public string $successRedirect,
        public string $failureRedirect,
        public int $clockSkew,
        public int $flowTtl,
        public int $sessionMaxAge,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(string $name, array $raw): self
    {
        if (!array_key_exists($name, self::NAMING)) {
            throw new InvalidArgumentException('Unknown OIDC provider: ' . $name);
        }
        $prefix = 'extensions.oidc-login.' . $name . '.';
        $enabled = Cast::bool($raw['enabled'] ?? null, $prefix . 'enabled', false);
        $key = Cast::trimmedString($raw['session_key'] ?? null, $prefix . 'session_key', $name . '.user');
        $maxAge = Cast::int($raw['session_max_age'] ?? null, $prefix . 'session_max_age', 28_800);
        if ($maxAge < 0) {
            throw new InvalidArgumentException($prefix . 'session_max_age must be 0 or a number of seconds.');
        }

        return new self(
            name: $name,
            enabled: $enabled,
            registration: $name === 'microsoft'
                ? MicrosoftRegistration::fromArray($raw, $enabled)
                : GoogleRegistration::fromArray($raw, $enabled),
            sessionKey: $key === '' ? $name . '.user' : $key,
            successRedirect: self::path($raw['success_redirect'] ?? null, $prefix . 'success_redirect'),
            failureRedirect: self::path($raw['failure_redirect'] ?? null, $prefix . 'failure_redirect'),
            clockSkew: min(300, max(0, Cast::int($raw['clock_skew'] ?? null, $prefix . 'clock_skew', 60))),
            flowTtl: max(30, Cast::int($raw['flow_ttl'] ?? null, $prefix . 'flow_ttl', 600)),
            sessionMaxAge: $maxAge,
        );
    }

    public function reason(string $code): string
    {
        return self::NAMING[$this->name]['reason'] . $code;
    }

    public function token(string $suffix): string
    {
        return self::NAMING[$this->name]['prefix'] . '_' . $suffix;
    }

    public function block(string $suffix): string
    {
        return 'Is' . self::NAMING[$this->name]['block'] . $suffix;
    }

    public static function redirectUri(mixed $value, bool $enabled, string $provider): string
    {
        $uri = Cast::trimmedString($value, 'extensions.oidc-login.' . $provider . '.redirect_uri');
        if ($uri === '' && !$enabled) {
            return '';
        }

        $url = Url::parse($uri);
        if ($url === null) {
            throw new InvalidArgumentException(
                'extensions.oidc-login.' . $provider . '.redirect_uri must be an absolute http(s) URL, without credentials, spaces or backslashes.'
            );
        }
        if ($url->fragment !== null) {
            throw new InvalidArgumentException('extensions.oidc-login.' . $provider . '.redirect_uri must not contain a fragment.');
        }
        // An IPv6 literal keeps its brackets: `[::1]`, not `::1`.
        if ($url->scheme !== 'https' && !in_array(trim($url->host, characters: '[]'), ['localhost', '127.0.0.1', '::1'], strict: true)) {
            throw new InvalidArgumentException('extensions.oidc-login.' . $provider . '.redirect_uri must use https outside localhost.');
        }

        return $uri;
    }

    private static function path(mixed $value, string $key): string
    {
        return Url::configuredPath(Cast::string($value, $key), 'INVALID_OIDC_LOGIN_REDIRECT_PATH');
    }
}
