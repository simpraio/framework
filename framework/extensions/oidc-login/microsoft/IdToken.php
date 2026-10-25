<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use extensions\oidc_login\LoginException;
use extensions\oidc_login\microsoft\groups\Directory;
use extensions\oidc_login\oidc\Audience;
use extensions\oidc_login\oidc\Claims as TokenClaims;
use extensions\oidc_login\oidc\Window;
use extensions\oidc_login\Provider;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Only for tokens received directly from Microsoft's authenticated HTTPS token endpoint.
 * OIDC Core 3.1.3.7 permits relying on that transport instead of checking the signature.
 * Browser-supplied ID tokens must never enter this path; they require JWKS verification.
 */
final readonly class IdToken
{
    /** @param list<string>|null $groups Group object IDs, or null when the token carried none. */
    private function __construct(
        public string $subject,
        public string $objectId,
        public string $tenantId,
        public string $email,
        public string $name,
        public ?array $groups,
        public bool $groupsOverflowed,
    ) {
    }

    public static function parse(string $jwt, Provider $config, string $nonce): self
    {
        $claims = TokenClaims::fromJwt($jwt, $config->reason('TOKEN_INVALID'));

        $registration = $config->registration;
        if (!$registration instanceof Registration) {
            throw new InvalidArgumentException('Microsoft validation requires a Microsoft registration.');
        }
        $tenantId = self::assertOrigin($claims, $config, $registration);
        Audience::assert($claims, $config, $nonce);
        Window::assert($claims, $config, time());

        $subject = $claims->str('sub');
        if ($subject === '') {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'sub claim missing');
        }

        $objectId = strtolower($claims->str('oid'));
        if (!Guid::isValid($objectId)) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'oid claim missing or invalid');
        }

        [$groups, $overflowed] = Claims::groups($claims);

        return new self(
            subject: $subject,
            objectId: $objectId,
            tenantId: $tenantId,
            email: Claims::email($claims),
            name: $claims->str('name'),
            groups: $groups,
            groupsOverflowed: $overflowed,
        );
    }

    private static function assertOrigin(TokenClaims $claims, Provider $config, Registration $registration): string
    {
        $tenantId = strtolower($claims->str('tid'));
        if ($tenantId === '') {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'tid claim missing');
        }

        // Issuer matching alone cannot pin a tenant: the expected issuer uses the token's tid.
        if ($registration->pinsTenant() && !hash_equals($registration->tenant, $tenantId)) {
            throw new LoginException($config->reason('TENANT_MISMATCH'));
        }

        if (!hash_equals($registration->expectedIssuer($tenantId), $claims->str('iss'))) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'issuer mismatch');
        }

        return $tenantId;
    }

    /** @return array<string, mixed> */
    public function profile(Provider $config, #[SensitiveParameter] string $accessToken): array
    {
        $registration = $config->registration;
        if (!$registration instanceof Registration) {
            throw new InvalidArgumentException('Microsoft validation requires a Microsoft registration.');
        }
        $membership = Directory::resolve($config, $this, $accessToken);
        $refusal = $registration->groups->refusalFor($membership);
        if ($refusal !== null) {
            throw new LoginException($refusal, $membership->reason);
        }
        return [
            'sub' => $this->subject, 'oid' => $this->objectId, 'tid' => $this->tenantId,
            'email' => $this->email, 'name' => $this->name,
            'groups' => $membership->groups, 'groups_known' => $membership->known,
        ];
    }
}
