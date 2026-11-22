<?php

declare(strict_types=1);

namespace extensions\oidc_login\microsoft;

use core\config\Cast;
use InvalidArgumentException;

/**
 * Only the selected credential is validated and used; failure must never select the other one.
 */
enum ClientCredential: string
{
    case Secret = 'secret';

    case Certificate = 'certificate';

    /** Disabled providers tolerate unknown selector names without requiring credentials. */
    public static function fromConfig(mixed $value, bool $enabled): self
    {
        $name = Cast::trimmedString($value, 'extensions.oidc-login.microsoft.client_credential', self::Secret->value);
        $credential = self::tryFrom($name);
        if ($credential === null && $enabled) {
            throw new InvalidArgumentException(
                "extensions.oidc-login.microsoft.client_credential must be 'secret' or 'certificate'."
            );
        }

        return $credential ?? self::Secret;
    }
}
