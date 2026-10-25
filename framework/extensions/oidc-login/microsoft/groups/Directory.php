<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft\groups;

use Exception;
use extensions\oidc_login\LoginException;
use extensions\oidc_login\microsoft\IdToken;
use extensions\oidc_login\microsoft\Registration;
use extensions\oidc_login\oidc\Token;
use extensions\oidc_login\Provider;
use SensitiveParameter;

/**
 * Resolve group overage through Graph. Lookup failures remain unknown membership;
 * Policy decides whether unavailable membership must refuse sign-in.
 */
final class Directory
{
    public static function resolve(
        Provider $config,
        IdToken $identity,
        #[SensitiveParameter]
        string $accessToken,
    ): Membership {
        if ($identity->groupsOverflowed) {
            return self::viaGraph($config, $accessToken);
        }

        if ($identity->groups === null) {
            // An absent groups claim is unknown membership, not an empty known list.
            return Membership::unknown(
                'the id_token carried no groups claim - add the groups claim (Group ID) to the app registration'
            );
        }

        return Membership::of($identity->groups);
    }

    private static function viaGraph(
        Provider $config,
        #[SensitiveParameter]
        string $accessToken,
    ): Membership {
        if (!$config->registration instanceof Registration || !$config->registration->groups->graphFallback) {
            return Membership::unknown('membership exceeds the groups claim limit and graph_fallback is off');
        }

        if ($accessToken === '') {
            return Membership::unknown('membership exceeds the groups claim limit and no access token was issued');
        }

        try {
            return self::fromGraph($config, $accessToken);
        } catch (Exception $e) {
            // A lookup failure must not deny sign-in when no group is required.
            return Membership::unknown($e->getMessage());
        }
    }

    /** getMemberGroups includes transitive membership without pagination. */
    private static function fromGraph(
        Provider $config,
        #[SensitiveParameter]
        string $accessToken,
    ): Membership {
        $url = 'https://graph.microsoft.com/v1.0/me/getMemberGroups';
        $body = json_encode(['securityEnabledOnly' => false], JSON_THROW_ON_ERROR);
        $response = Token::post($config, $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'data' => $body,
            'raw' => true,
        ]);

        if ($response->status !== 200) {
            throw new LoginException(
                'MS_LOGIN_GRAPH_REFUSED',
                'Graph getMemberGroups returned HTTP ' . $response->status
            );
        }

        $payload = is_array($response->json) ? $response->json : [];
        /** @var mixed $value */
        $value = $payload['value'] ?? null;
        if (!is_array($value)) {
            throw new LoginException('MS_LOGIN_GRAPH_REFUSED', 'Graph response had no value array');
        }

        return Membership::of($value);
    }
}
