<?php

declare(strict_types=1);

namespace extensions\oidc_login\google;

use core\config\Cast;
use core\config\RedactsSecrets;
use extensions\oidc_login\oidc\Registration as OidcRegistration;
use extensions\oidc_login\Provider;
use InvalidArgumentException;
use JsonSerializable;
use SensitiveParameter;

final readonly class Registration implements OidcRegistration, JsonSerializable
{
    use RedactsSecrets;

    private const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const string SCOPES = 'openid email profile';

    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        public string $redirectUri,
        public Policy $domains,
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
        $clientId = Cast::trimmedString($raw['client_id'] ?? null, 'extensions.oidc-login.google.client_id');
        $secret = Cast::trimmedString($raw['client_secret'] ?? null, 'extensions.oidc-login.google.client_secret');
        if ($enabled && ($clientId === '' || $secret === '')) {
            throw new InvalidArgumentException('extensions.oidc-login.google requires client_id and client_secret when enabled.');
        }

        return new self(
            $clientId,
            $secret,
            Provider::redirectUri($raw['redirect_uri'] ?? null, $enabled, 'google'),
            Policy::fromArray($raw),
        );
    }

    public function authorizeUrl(): string
    {
        return self::AUTHORIZE_URL;
    }

    public function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    public function scopeList(): string
    {
        return self::SCOPES;
    }

    public function sendsScopeOnRedemption(): bool
    {
        return false;
    }

    /**
     * hd is an account-chooser hint, never proof of membership.
     *
     * @return array<string, string>
     */
    public function authorizeHints(): array
    {
        return count($this->domains->allowed) === 1 ? ['hd' => $this->domains->allowed[0]] : [];
    }

    public function identityClaim(): string
    {
        return 'sub';
    }

    /** @return array<string, mixed> */
    public function identify(
        Provider $config,
        #[SensitiveParameter] string $idToken,
        string $nonce,
        #[SensitiveParameter] string $accessToken,
    ): array {
        return IdToken::parse($idToken, $config, $nonce)->profile();
    }

}
