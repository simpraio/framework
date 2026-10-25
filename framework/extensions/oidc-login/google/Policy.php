<?php

declare(strict_types=1);

namespace extensions\oidc_login\google;

use InvalidArgumentException;

/**
 * Domain restrictions use the hd claim, never the email suffix or account-chooser hint.
 * Missing hd means no hosted-domain membership, not an unavailable lookup.
 */
final readonly class Policy
{
    /** @param list<string> $allowed Workspace domains, lowercased. */
    public function __construct(public array $allowed)
    {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(self::domains(array_key_exists('allowed_domains', $raw) ? $raw['allowed_domains'] : []));
    }

    public function required(): bool
    {
        return $this->allowed !== [];
    }

    public function refusalFor(string $hostedDomain): ?string
    {
        if (!$this->required()) {
            return null;
        }

        return $this->satisfiedBy($hostedDomain) ? null : 'GOOGLE_LOGIN_DOMAIN_DENIED';
    }

    /** Subdomains do not satisfy a parent-domain restriction. */
    public function satisfiedBy(string $hostedDomain): bool
    {
        if (!$this->required()) {
            return true;
        }

        return in_array(strtolower(trim($hostedDomain)), $this->allowed, strict: true);
    }

    /** @return list<string> */
    private static function domains(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('extensions.oidc-login.google.allowed_domains must be an array.');
        }

        $domains = [];
        /** @var mixed $item */
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('extensions.oidc-login.google.allowed_domains entries must be domain names.');
            }

            // Domain restrictions are literal hostnames, not patterns.
            $domain = strtolower(trim($item));
            if (!str_contains($domain, '.') || str_ends_with($domain, '.')
                || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            ) {
                throw new InvalidArgumentException('extensions.oidc-login.google.allowed_domains entries must be domain names.');
            }

            if (!in_array($domain, $domains, strict: true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }
}
