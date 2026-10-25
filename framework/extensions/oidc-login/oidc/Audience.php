<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

use extensions\oidc_login\LoginException;
use extensions\oidc_login\Provider;

/**
 * Binds an ID token to this client and to this sign-in (OIDC Core 3.1.3.7).
 * aud must be this client's ID alone; azp, when present, must be too.
 */
final class Audience
{
    public static function assert(Claims $claims, Provider $config, string $nonce): void
    {
        $clientId = $config->registration->clientId;

        if (!hash_equals($clientId, $claims->str('aud'))) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'audience mismatch');
        }
        if ($claims->has('azp') && !hash_equals($clientId, $claims->str('azp'))) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'authorized party mismatch');
        }

        // A matching audience proves the token is for this application, not for this sign-in.
        if ($nonce === '' || !hash_equals($nonce, $claims->str('nonce'))) {
            throw new LoginException($config->reason('NONCE_MISMATCH'));
        }
    }
}
