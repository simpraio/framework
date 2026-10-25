<?php

declare(strict_types=1);

namespace extensions\oidc_login;

use core\Session;
use extensions\oidc_login\microsoft\groups\Membership;
use InvalidArgumentException;

final class User
{
    public static function current(string $provider): object
    {
        $config = Config::enabled($provider);
        if (!Session::isStarted() && !Session::hasCookie()) {
            return (object)[];
        }
        /** @var mixed $data */
        $data = Session::get($config->sessionKey);
        if (!is_array($data) || !is_string($data['sub'] ?? null) || $data['sub'] === '') {
            return (object)[];
        }
        if (self::outlived($data, $config)) {
            Session::forget($config->sessionKey);
            return (object)[];
        }
        return (object)$data;
    }

    /**
     * The provider is never asked again after sign-in, so an account disabled there, or removed
     * from a required group, stays signed in here until this age forces a new sign-in. A stamp
     * up to clock_skew ahead of this host's clock was made by another host and still counts.
     *
     * @param array<array-key, mixed> $data
     */
    private static function outlived(array $data, Provider $config): bool
    {
        $maxAge = $config->sessionMaxAge;
        if ($maxAge === 0) {
            return false;
        }
        /** @var mixed $at */
        $at = $data['authenticated_at'] ?? null;
        if (!is_int($at)) {
            return true;
        }
        $age = time() - $at;

        return $age < -$config->clockSkew || $age >= $maxAge;
    }

    public static function isAuthenticated(string $provider): bool
    {
        return self::subject($provider) !== '';
    }

    public static function isGuest(string $provider): bool
    {
        return !self::isAuthenticated($provider);
    }

    public static function id(string $provider): string
    {
        /** @var mixed $value */
        $value = self::profile($provider, Config::enabled($provider)->registration->identityClaim());
        return is_string($value) ? $value : '';
    }

    public static function subject(string $provider): string
    {
        return (string)(self::current($provider)->sub ?? '');
    }

    public static function email(string $provider): string
    {
        return (string)(self::current($provider)->email ?? '');
    }

    public static function name(string $provider): string
    {
        return (string)(self::current($provider)->name ?? '');
    }

    public static function tenantId(string $provider): string
    {
        if ($provider !== 'microsoft') {
            throw new InvalidArgumentException('Tenant IDs are only available for Microsoft.');
        }
        return (string)(self::current($provider)->tid ?? '');
    }

    public static function emailVerified(string $provider): bool
    {
        if ($provider !== 'google') {
            throw new InvalidArgumentException('Email verification is only available for Google.');
        }
        return (self::current($provider)->email_verified ?? false) === true;
    }

    public static function hostedDomain(string $provider): string
    {
        if ($provider !== 'google') {
            throw new InvalidArgumentException('Hosted domains are only available for Google.');
        }
        return (string)(self::current($provider)->hd ?? '');
    }

    /** @return list<string> */
    public static function groups(string $provider): array
    {
        return self::membership($provider)->groups;
    }

    public static function groupsKnown(string $provider): bool
    {
        return self::membership($provider)->known;
    }

    /** @param string|list<string> $groups */
    public static function inGroup(string $provider, string|array $groups, bool $all = false): bool
    {
        return self::membership($provider)->contains($groups, $all);
    }

    public static function profile(string $provider, ?string $key = null, mixed $default = null): mixed
    {
        $user = self::current($provider);
        if ($key === null) {
            return $user;
        }
        $data = get_object_vars($user);
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /** @param array<string, mixed> $user */
    public static function set(string $provider, array $user): void
    {
        // session_max_age counts from here unless the caller says when the sign-in happened.
        Session::set(Config::enabled($provider)->sessionKey, $user + ['authenticated_at' => time()]);
    }

    public static function clear(string $provider): void
    {
        Session::forget(Config::enabled($provider)->sessionKey);
    }

    private static function membership(string $provider): Membership
    {
        if ($provider !== 'microsoft') {
            throw new InvalidArgumentException('Group membership is only available for Microsoft.');
        }
        /** @var array<string, mixed> $data */
        $data = get_object_vars(self::current($provider));
        return Membership::fromSession($data);
    }
}
