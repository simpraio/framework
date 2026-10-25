<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use extensions\oidc_login\oidc\Claims as TokenClaims;
use extensions\oidc_login\microsoft\groups\Membership;

final class Claims
{
    public static function email(TokenClaims $claims): string
    {
        foreach (['email', 'preferred_username', 'upn'] as $key) {
            $value = $claims->str($key);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Missing inline groups may mean overage, not empty membership.
     *
     * @return array{0: list<string>|null, 1: bool}
     */
    public static function groups(TokenClaims $claims): array
    {
        /** @var mixed $names */
        $names = $claims->values['_claim_names'] ?? null;
        if (is_array($names) && array_key_exists('groups', $names)) {
            return [null, true];
        }

        /** @var mixed $raw */
        $raw = $claims->values['groups'] ?? null;
        if (!is_array($raw)) {
            return [null, false];
        }

        return [Membership::of($raw)->groups, false];
    }
}
