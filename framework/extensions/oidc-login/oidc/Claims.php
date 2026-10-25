<?php

declare(strict_types=1);

namespace extensions\oidc_login\oidc;

use extensions\oidc_login\LoginException;

final readonly class Claims
{
    /** @param array<string, mixed> $values */
    private function __construct(public array $values)
    {
    }

    public static function fromJwt(string $jwt, string $reason): self
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new LoginException($reason, 'not a three-part JWT');
        }
        $payload = Encoding::decode($parts[1]);
        if ($payload === null) {
            throw new LoginException($reason, 'payload is not base64url');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($payload, associative: true, depth: 16);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new LoginException($reason, 'payload is not a JSON object');
        }
        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public function str(string $key): string
    {
        /** @var mixed $value */
        $value = $this->values[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * Preserve RFC 7519 fractional seconds; rounding changes validity boundaries.
     * Null denotes missing or invalid data, distinct from the Unix epoch (0).
     */
    public function timestamp(string $key): int|float|null
    {
        /** @var mixed $value */
        $value = $this->values[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        return null;
    }

    public function bool(string $key): bool
    {
        /** @var mixed $value */
        $value = $this->values[$key] ?? null;
        return $value === true || $value === 'true';
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
