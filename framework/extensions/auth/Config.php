<?php

declare(strict_types=1);

namespace extensions\auth;

use core\config\Cast;
use core\config\Config as CoreConfig;
use core\http\Url;
use InvalidArgumentException;

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
        public string $returnParam,
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
            // Empty is the opt-out, and a meaningful one: the page a guest asked for ends up in
            // the sign-in URL, so it reaches access logs and browser history. A project whose URLs
            // carry anything sensitive in a query string turns this off rather than forking.
            returnParam: Cast::string($raw['return_param'] ?? null, 'extensions.auth.return_param', 'return'),
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function redirectPath(string $configured): string
    {
        return Url::configuredPath($configured, 'INVALID_AUTH_REDIRECT_PATH');
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
