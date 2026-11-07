<?php

declare(strict_types=1);

namespace extensions\auth;

/**
 * Checks a password in no less time than a miss takes, so a quick answer cannot show that an account
 * exists or holds a cheap hash: a bcrypt hash cheaper than the default cost is topped up to it, and a
 * value that is no hash at all takes the dummy's time. A bcrypt hash costlier than the default, or
 * another algorithm such as Argon2, answers in its own time - set by how that account's password was
 * stored, which a miss cannot imitate.
 */
final class PasswordCheck
{
    /** The hash of the empty password, which a login never offers, at bcrypt's default cost. */
    private const string DUMMY_HASH = '$2y$12$Rzce1gHVaaG3W5FSjv4Ddutc88U28fzPvQnQVh3o722CEEtmRXaaq';

    /** null checks against the dummy hash: there is no account to check against. */
    public static function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        $valid = password_verify($password, $hash ?? self::DUMMY_HASH);
        self::spendFullCost($password, $hash ?? self::DUMMY_HASH);

        return $valid;
    }

    /** Spends what a check takes without checking anything, for an attempt refused before one. */
    public static function spend(#[\SensitiveParameter] string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    /**
     * bcrypt's work doubles per cost step, so hashing at costs c to 11 after a check at cost c spends
     * what one check at 12 does; a value that is no hash at all is followed by a check against the dummy.
     */
    private static function spendFullCost(#[\SensitiveParameter] string $password, string $hash): void
    {
        $info = password_get_info($hash);
        if ($info['algoName'] === 'unknown') {
            self::spend($password);
            return;
        }
        if ($info['algo'] !== PASSWORD_BCRYPT) {
            return;
        }
        for ($cost = max(4, (int)($info['options']['cost'] ?? 0)); $cost < PASSWORD_BCRYPT_DEFAULT_COST; $cost++) {
            password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
        }
    }
}
