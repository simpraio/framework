<?php

declare(strict_types=1);

namespace extensions\oidc_login\google;

use extensions\oidc_login\LoginException;
use extensions\oidc_login\oidc\Audience;
use extensions\oidc_login\oidc\Claims;
use extensions\oidc_login\oidc\Window;
use extensions\oidc_login\Provider;
use InvalidArgumentException;

/**
 * Only for tokens received directly from Google's authenticated HTTPS token endpoint.
 * OIDC Core 3.1.3.7 permits relying on that transport instead of checking the signature.
 * Browser-supplied ID tokens must never enter this path; they require JWKS verification.
 */
final readonly class IdToken
{
    private function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public string $name,
        public string $hostedDomain,
    ) {
    }

    public static function parse(string $jwt, Provider $config, string $nonce): self
    {
        $registration = $config->registration;
        if (!$registration instanceof Registration) {
            throw new InvalidArgumentException('Google validation requires a Google registration.');
        }
        $claims = Claims::fromJwt($jwt, $config->reason('TOKEN_INVALID'));
        if (!in_array($claims->str('iss'), ['https://accounts.google.com', 'accounts.google.com'], strict: true)) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'issuer mismatch');
        }
        Audience::assert($claims, $config, $nonce);
        Window::assert($claims, $config, time());

        $subject = $claims->str('sub');
        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $subject) !== 1) {
            throw new LoginException($config->reason('TOKEN_INVALID'), 'sub claim missing or invalid');
        }

        // Only the hd claim proves Workspace membership, not the email suffix or request hint.
        $domain = strtolower($claims->str('hd'));
        $refusal = $registration->domains->refusalFor($domain);
        if ($refusal !== null) {
            throw new LoginException($refusal);
        }
        $email = $claims->str('email');
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';

        return new self($subject, $email, $email !== '' && $claims->bool('email_verified'), $claims->str('name'), $domain);
    }

    /** @return array<string, mixed> */
    public function profile(): array
    {
        return [
            'sub' => $this->subject, 'email' => $this->email, 'email_verified' => $this->emailVerified,
            'name' => $this->name, 'hd' => $this->hostedDomain,
        ];
    }
}
