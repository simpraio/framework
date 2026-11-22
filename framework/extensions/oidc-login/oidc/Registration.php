<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

use extensions\oidc_login\Provider;
use SensitiveParameter;

interface Registration
{
    public string $clientId { get; }

    public string $redirectUri { get; }

    public function authorizeUrl(): string;

    public function tokenUrl(): string;

    public function scopeList(): string;

    /**
     * The token endpoint fields that authenticate this client (a secret, or a signed client
     * assertion). Throws LoginException when the credential cannot be used, before any request.
     *
     * @return array<string, string>
     */
    public function clientAuthentication(Provider $config): array;

    /** Whether redemption repeats the scope list. Microsoft expects it; Google does not. */
    public function sendsScopeOnRedemption(): bool;

    /**
     * @return array<string, string>
     */
    public function authorizeHints(): array;

    public function identityClaim(): string;

    /**
     * @return array<string, mixed>
     */
    public function identify(
        Provider $config,
        #[SensitiveParameter] string $idToken,
        string $nonce,
        #[SensitiveParameter] string $accessToken,
    ): array;
}
