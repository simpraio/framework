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

        $logout = Cast::string($raw['logout_redirect'] ?? null, 'extensions.auth.logout_redirect');
        $guest = Cast::string($raw['guest_route'] ?? null, 'extensions.auth.guest_route', 'login');
        $denied = Cast::string($raw['denied_redirect'] ?? null, 'extensions.auth.denied_redirect');

        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.auth.enabled', false),
            sessionKey: Cast::string($raw['session_key'] ?? null, 'extensions.auth.session_key', 'user.data'),
            guestGroup: Cast::string($raw['guest_group'] ?? null, 'extensions.auth.guest_group', 'guest'),
            logoutRedirect: self::redirectPath($logout),
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
            guestRoute: self::redirectPath($guest),
            deniedRedirect: self::redirectPath($denied),
        );
    }

    /**
     * A configured route as the path to redirect to.
     *
     * A leading slash means the value is the path exactly as written, so a project whose
     * canonical URLs carry no trailing slash can configure `/login` and get `/login`
     * rather than a redirect to `/login/` that its own rewrite then sends back.
     *
     * A bare name keeps the `/name/` shape this has always produced, so a configuration
     * written before the distinction existed still means what it meant.
     *
     * Leading slashes and backslashes collapse, so the result always names a path on this
     * site: `//host` is a protocol-relative URL naming another origin, and a browser reads
     * the `/\host` form the same way, since it treats a backslash in a URL as a slash.
     *
     * Public because {@see Auth::logout()} takes the same value as an argument, and a route
     * given there has to mean what the configured one means.
     */
    public static function redirectPath(string $configured): string
    {
        $value = trim($configured);
        $segments = trim(string: $value, characters: "/\\");

        if ($segments === '') {
            return '/';
        }

        if (!str_starts_with($value, '/')) {
            return '/' . $segments . '/';
        }

        return '/' . $segments . (str_ends_with($value, '/') ? '/' : '');
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
