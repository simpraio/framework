<?php

declare(strict_types=1);

namespace extensions\auth;

use core\db\Db;
use core\Session;

final class State
{
    public static function fromSession(): object
    {
        $config = Config::enabled();

        $key = $config->sessionKey;

        if ($key === '') {
            return self::guest();
        }

        /** @var mixed $data */
        $data = Session::get($key);
        if (!is_array($data) || $data === []) {
            return self::guest();
        }

        $userId = (int)($data['user_id'] ?? 0);
        if ($userId <= 0) {
            return self::guest();
        }

        $lastValidatedAt = (int)($data['last_validated_at'] ?? 0);
        $now = time();

        if ($lastValidatedAt === 0 || $now < $lastValidatedAt || $now - $lastValidatedAt >= $config->revalidateInterval) {
            $row = Db::row(
                'SELECT `group_id`, `password`, `status` FROM `auth_user` WHERE `user_id` = :user_id',
                ['user_id' => $userId]
            );

            $validated = self::revalidated($data, $row, $now);
            if ($validated === null) {
                Session::destroy();
                return self::guest();
            }

            $data = $validated;
            Session::set($key, $data);
        }

        return (object)$data;
    }

    public static function guest(): object
    {
        Config::enabled();

        return (object)[
            'user_id' => 0,
            'group_id' => Groups::guestId(),
        ];
    }

    /**
     * Session-bound fingerprint of the stored password hash. Auth::login() puts it in the
     * session and revalidation re-derives it from the row, so changing (or resetting) a
     * password invalidates every session that still carries the old credential. Without it a
     * stolen session survives the password change that was meant to end it. The bcrypt hash
     * itself never enters the session; only this digest of it does.
     */
    public static function credentialFingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    /**
     * The session data to keep, or null when the session must be destroyed.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, mixed>|null $row
     * @return array<array-key, mixed>|null
     */
    private static function revalidated(array $data, ?array $row, int $now): ?array
    {
        if ($row === null || ($row['status'] ?? '') !== 'active') {
            return null;
        }

        $fingerprint = self::credentialFingerprint((string)($row['password'] ?? ''));
        /** @var mixed $sessionFingerprint */
        $sessionFingerprint = $data['credential_fingerprint'] ?? null;
        if (is_string($sessionFingerprint) && !hash_equals($sessionFingerprint, $fingerprint)) {
            return null;
        }

        // A session created before fingerprints existed carries none, so it is upgraded in
        // place rather than destroyed. Refreshing group_id here also lets a group change take
        // effect on the next revalidation instead of only on the next login.
        $data['group_id'] = (int)($row['group_id'] ?? Groups::guestId());
        $data['credential_fingerprint'] = $fingerprint;
        $data['last_validated_at'] = $now;

        return $data;
    }
}
