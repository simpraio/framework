<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

use extensions\oidc_login\LoginException;
use extensions\oidc_login\Provider;

/**
 * OIDC requires exp and iat; nbf is optional but must be valid when present.
 * Clock skew widens the accepted window at both ends.
 */
final class Window
{
    public static function assert(Claims $claims, Provider $config, int $now): void
    {
        $skew = $config->clockSkew;

        $exp = $claims->timestamp('exp');
        if ($exp === null || $now - $skew >= $exp) {
            throw new LoginException($config->reason('TOKEN_EXPIRED'));
        }

        $iat = $claims->timestamp('iat');
        if ($iat === null || $iat > $now + $skew) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'invalid issue time');
        }

        if (!$claims->has('nbf')) {
            return;
        }

        $nbf = $claims->timestamp('nbf');
        if ($nbf === null || $nbf > $now + $skew) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'invalid not-before time');
        }
    }
}
