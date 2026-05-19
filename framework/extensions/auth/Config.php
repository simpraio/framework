<?php

declare(strict_types=1);

namespace extensions\auth;

use core\config\Cast;
use core\config\Config as CoreConfig;

final readonly class Config
{
    public const string NAME = 'auth';
    public const string DISABLED = 'AUTH_DISABLED';

    public function __construct(
        public bool $enabled,
        public string $sessionKey,
        public string $guestGroup,
        public string $logoutRedirect,
        public int $rateLimitAttempts,
        public int $rateLimitWindow,
        public int $revalidateInterval,
        public string $defaultPolicy,
        public string $guestRoute,
        public string $deniedRedirect,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $policy = Cast::string($raw['default_policy'] ?? null, 'extensions.auth.default_policy', 'deny');

        $logout = trim(
            string: Cast::string($raw['logout_redirect'] ?? null, 'extensions.auth.logout_redirect'),
            characters: '/'
        );
        $guest = trim(
            string: Cast::string($raw['guest_route'] ?? null, 'extensions.auth.guest_route', 'login'),
            characters: '/'
        );
        $denied = trim(
            string: Cast::string($raw['denied_redirect'] ?? null, 'extensions.auth.denied_redirect'),
            characters: '/'
        );

        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.auth.enabled', false),
            sessionKey: Cast::string($raw['session_key'] ?? null, 'extensions.auth.session_key', 'user.data'),
            guestGroup: Cast::string($raw['guest_group'] ?? null, 'extensions.auth.guest_group', 'guest'),
            logoutRedirect: $logout !== '' ? '/' . $logout . '/' : '/',
            rateLimitAttempts: max(
                1,
                Cast::int($raw['login_attempts'] ?? null, 'extensions.auth.login_attempts', 5)
            ),
            rateLimitWindow: max(
                1,
                Cast::int($raw['login_attempts_window'] ?? null, 'extensions.auth.login_attempts_window', 900)
            ),
            revalidateInterval: max(
                0,
                Cast::int($raw['revalidate_interval'] ?? null, 'extensions.auth.revalidate_interval', 60)
            ),
            defaultPolicy: in_array($policy, ['allow', 'deny'], strict: true) ? $policy : 'deny',
            guestRoute: $guest !== '' ? '/' . $guest . '/' : '/',
            deniedRedirect: $denied !== '' ? '/' . $denied . '/' : '/',
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
