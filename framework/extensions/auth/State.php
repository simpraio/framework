<?php

declare(strict_types=1);

namespace extensions\auth;

use core\db\Db;
use core\Session;
use SensitiveParameter;

final class State
{
    public static function fromSession(): object
    {
        $config = Config::enabled();

        $key = $config->sessionKey;

        if ($key === '') {
            return self::guest();
        }

        // Guest checks must not create sessions.
        if (!Session::isStarted() && !Session::hasCookie()) {
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
     * Password changes invalidate the fingerprint; the password hash itself stays out of sessions.
     */
    public static function credentialFingerprint(#[SensitiveParameter] string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    /**
     * Bind an already-authenticated account to its current stored credential, including any rehash.
     * External sign-ins use the stored value too; an empty string means the database value is empty.
     *
     * @param array<string, mixed> $user the account row; anything in it is carried through
     * @return array<string, mixed>
     */
    public static function sessionPayload(array $user, #[SensitiveParameter] string $passwordHash): array
    {
        $user['credential_fingerprint'] = self::credentialFingerprint($passwordHash);
        unset($user['password']);
        $user['last_validated_at'] = time();

        return $user;
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

        $data['group_id'] = (int)($row['group_id'] ?? Groups::guestId());
        $data['credential_fingerprint'] = $fingerprint;
        $data['last_validated_at'] = $now;

        return $data;
    }
}
