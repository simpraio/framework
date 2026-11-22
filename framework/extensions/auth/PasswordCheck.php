<?php

declare(strict_types=1);

namespace extensions\auth;

/**
 * Pad cheap or invalid hashes to the default bcrypt workload to limit account enumeration.
 * Stronger bcrypt costs and other algorithms retain their own timing; this is not constant-time.
 */
final class PasswordCheck
{
    /** The hash of the empty password, which a login never offers, at bcrypt's default cost. */
    private const string DUMMY_HASH = '$2y$12$Rzce1gHVaaG3W5FSjv4Ddutc88U28fzPvQnQVh3o722CEEtmRXaaq';

    public static function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        $valid = password_verify($password, $hash ?? self::DUMMY_HASH);
        self::spendFullCost($password, $hash ?? self::DUMMY_HASH);

        return $valid;
    }

    public static function spend(#[\SensitiveParameter] string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    /**
     * bcrypt work doubles per cost step: the verification plus costs c through default-1 totals default work.
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
