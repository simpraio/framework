<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

use core\config\Config as CoreConfig;
use core\http\Url;
use Exception;
use extensions\http_client\Config as HttpConfig;
use extensions\http_client\HttpClient;
use extensions\http_client\Response;
use extensions\oidc_login\LoginException;
use extensions\oidc_login\Provider;
use SensitiveParameter;

final class Token
{
    /** @return array{id_token: string, access_token: string} */
    public static function exchange(Provider $config, #[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): array
    {
        $registration = $config->registration;
        $data = [
            'grant_type' => 'authorization_code',
            'client_id' => $registration->clientId,
            'client_secret' => $registration->clientSecret,
            'code' => $code,
            'redirect_uri' => $registration->redirectUri,
            'code_verifier' => $verifier,
        ];
        if ($registration->sendsScopeOnRedemption()) {
            $data['scope'] = $registration->scopeList();
        }
        $response = self::post($config, $registration->tokenUrl(), [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'data' => $data,
        ]);
        if ($response->status !== 200) {
            throw new LoginException($config->reason('TOKEN_REQUEST_FAILED'), 'token endpoint returned HTTP ' . $response->status);
        }
        /** @var mixed $idToken */
        $idToken = is_array($response->json) ? ($response->json['id_token'] ?? null) : null;
        if (!is_string($idToken) || $idToken === '') {
            throw new LoginException($config->reason('TOKEN_REQUEST_FAILED'), 'no id_token in token response');
        }
        /** @var mixed $accessToken */
        $accessToken = is_array($response->json) ? ($response->json['access_token'] ?? null) : null;
        return ['id_token' => $idToken, 'access_token' => is_string($accessToken) ? $accessToken : ''];
    }

    /** @param array<string, mixed> $options */
    public static function post(Provider $config, string $url, #[SensitiveParameter] array $options): Response
    {
        if (!class_exists(HttpClient::class)) {
            throw new LoginException($config->reason('HTTP_UNAVAILABLE'), 'http-client must be vendored and enabled');
        }
        try {
            $http = CoreConfig::extensionConfig(HttpConfig::NAME, HttpConfig::class);
            if (!$http->enabled || !$http->verifyTls || Url::parse($url)?->scheme !== 'https') {
                throw new LoginException($config->reason('HTTP_UNAVAILABLE'));
            }
            // Token origin is established by the endpoint's TLS identity, not a redirect target.
            return HttpClient::post($url, [...$options, 'follow_redirects' => false]);
        } catch (Exception) {
            throw new LoginException($config->reason('HTTP_UNAVAILABLE'), 'verified provider connection unavailable');
        }
    }
}
