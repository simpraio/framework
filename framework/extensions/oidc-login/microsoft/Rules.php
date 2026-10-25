<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use core\config\Cast;
use InvalidArgumentException;

final class Rules
{
    /** Tenant values that accept sign-in from a directory other than your own. */
    public const array MULTI_TENANT = ['common', 'organizations', 'consumers'];

    public static function tenant(mixed $value, bool $enabled): string
    {
        $tenant = strtolower(Cast::trimmedString($value, 'extensions.oidc-login.microsoft.tenant'));

        if ($tenant === '') {
            if ($enabled) {
                throw new InvalidArgumentException('extensions.oidc-login.microsoft.tenant is required when enabled.');
            }

            return '';
        }

        if (in_array($tenant, self::MULTI_TENANT, strict: true)) {
            return $tenant;
        }

        // The tenant becomes an authority URL segment; arbitrary path text is not allowed.
        if (!Guid::isValid($tenant)) {
            throw new InvalidArgumentException(
                'extensions.oidc-login.microsoft.tenant must be a directory GUID, or one of: '
                . implode(', ', self::MULTI_TENANT)
            );
        }

        return $tenant;
    }

    /** @return list<string> */
    public static function scopes(mixed $value): array
    {
        $raw = is_array($value) ? array_values($value) : [];

        $scopes = [];
        /** @var mixed $scope */
        foreach ($raw as $scope) {
            $name = Cast::trimmedString($scope, 'extensions.oidc-login.microsoft.scopes[]');
            if ($name === '' || in_array($name, $scopes, strict: true)) {
                continue;
            }

            $scopes[] = $name;
        }

        // openid requests the ID token; profile supplies oid for User::id().
        if (!in_array('openid', $scopes, strict: true)) {
            array_unshift($scopes, 'openid');
        }
        if (!in_array('profile', $scopes, strict: true)) {
            $scopes[] = 'profile';
        }

        return $scopes;
    }
}
