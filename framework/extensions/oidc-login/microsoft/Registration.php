<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use core\config\Cast;
use core\config\RedactsSecrets;
use extensions\oidc_login\microsoft\groups\Policy;
use extensions\oidc_login\oidc\Registration as OidcRegistration;
use extensions\oidc_login\Provider;
use InvalidArgumentException;
use JsonSerializable;
use SensitiveParameter;

final readonly class Registration implements OidcRegistration, JsonSerializable
{
    use RedactsSecrets;

    private const string AUTHORITY = 'https://login.microsoftonline.com';

    /** @param list<string> $scopes */
    public function __construct(
        public string $tenant,
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        public string $redirectUri,
        public array $scopes,
        public Policy $groups,
    ) {
    }

    /** @return list<string> */
    protected function secretKeys(): array
    {
        return ['clientSecret'];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw, bool $enabled): self
    {
        $clientId = Cast::trimmedString($raw['client_id'] ?? null, 'extensions.oidc-login.microsoft.client_id');
        $clientSecret = Cast::trimmedString($raw['client_secret'] ?? null, 'extensions.oidc-login.microsoft.client_secret');

        if ($enabled && ($clientId === '' || $clientSecret === '')) {
            throw new InvalidArgumentException(
                'extensions.oidc-login.microsoft requires client_id and client_secret when enabled.'
            );
        }

        return new self(
            tenant: Rules::tenant($raw['tenant'] ?? null, $enabled),
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUri: Provider::redirectUri($raw['redirect_uri'] ?? null, $enabled, 'microsoft'),
            scopes: Rules::scopes($raw['scopes'] ?? null),
            groups: Policy::fromArray($raw),
        );
    }

    public function authorizeUrl(): string
    {
        return self::AUTHORITY . '/' . rawurlencode($this->tenant) . '/oauth2/v2.0/authorize';
    }

    public function tokenUrl(): string
    {
        return self::AUTHORITY . '/' . rawurlencode($this->tenant) . '/oauth2/v2.0/token';
    }

    /** Whether the configured tenant pins sign-in to one directory, so `tid` can be checked. */
    public function pinsTenant(): bool
    {
        return $this->tenant !== '' && !in_array($this->tenant, Rules::MULTI_TENANT, strict: true);
    }

    /** The issuer an id_token must carry, given the tenant it claims to come from. */
    public function expectedIssuer(string $tokenTenantId): string
    {
        return self::AUTHORITY . '/' . $tokenTenantId . '/v2.0';
    }

    public function scopeList(): string
    {
        return implode(' ', $this->scopes);
    }

    public function sendsScopeOnRedemption(): bool
    {
        return true;
    }

    /**
     * Entra puts the code in the query string rather than the fragment, so the callback
     * controller reads it server-side.
     *
     * @return array<string, string>
     */
    public function authorizeHints(): array
    {
        return ['response_mode' => 'query'];
    }

    public function identityClaim(): string
    {
        return 'oid';
    }

    /** @return array<string, mixed> */
    public function identify(
        Provider $config,
        #[SensitiveParameter] string $idToken,
        string $nonce,
        #[SensitiveParameter] string $accessToken,
    ): array {
        return IdToken::parse($idToken, $config, $nonce)->profile($config, $accessToken);
    }
}
