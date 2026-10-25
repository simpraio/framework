<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

use extensions\oidc_login\Provider;
use SensitiveParameter;

interface Registration
{
    public string $clientId { get; }

    public string $clientSecret { get; }

    public string $redirectUri { get; }

    public function authorizeUrl(): string;

    public function tokenUrl(): string;

    public function scopeList(): string;

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
