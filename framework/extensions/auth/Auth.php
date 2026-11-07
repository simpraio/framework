<?php

declare(strict_types=1);

namespace extensions\auth;

use core\db\Db;
use core\http\Response;
use core\Session;

final class Auth
{
    public static function login(string $username, #[\SensitiveParameter] string $password): bool
    {
        $config = Config::enabled();

        // bcrypt refuses a NUL byte with a ValueError; refused here, before anything depends on the account.
        if ($username === '' || $password === '' || str_contains($password, "\0")) {
            return false;
        }

        if (!RateLimit::ipAttempt()) {
            return false;
        }

        $user = self::find($username);
        // The account, not the spelling: the column's collation matches "admin", "Admin" and "admin  "
        // to one row. A name that matches no row is counted in its folded form.
        $subject = $user === null ? 'name:' . mb_strtolower(rtrim($username, characters: ' ')) : 'user:' . (int)$user['user_id'];
        if (!RateLimit::accountAttempt($subject)) {
            // As slow as a miss: a quick refusal would show which spellings share an existing account's counter.
            PasswordCheck::spend($password);
            return false;
        }

        $passwordHash = (string)($user['password'] ?? '');
        $passwordValid = PasswordCheck::verify($password, $user === null ? null : $passwordHash);

        if ($user === null || !$passwordValid || $user['status'] === 'disabled') {
            return false;
        }

        // Algorithm changes need an explicit policy; only raise an existing bcrypt cost here.
        if (self::isLegacyCost($passwordHash)) {
            $rehashed = self::rehash($username, $password, $user, $passwordHash);
            if ($rehashed === null) {
                return false;
            }
            [$user, $passwordHash] = $rehashed;
        }

        $user = State::sessionPayload($user, $passwordHash);

        $sessionKey = $config->sessionKey;

        Session::rotate();
        Session::set($sessionKey, $user);
        User::set($user);

        RateLimit::succeeded($subject);

        Db::execute(
            'UPDATE `auth_user` SET `last_login_at` = UTC_TIMESTAMP(6) WHERE `user_id` = :user_id',
            ['user_id' => (int)$user['user_id']],
        );

        return true;
    }

    private static function isLegacyCost(string $hash): bool
    {
        $info = password_get_info($hash);

        return $info['algo'] === PASSWORD_BCRYPT && (int)($info['options']['cost'] ?? 0) < PASSWORD_BCRYPT_DEFAULT_COST;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0: array<string, mixed>, 1: string}|null the account and the hash it now holds, or
     *     null when a reset or a removal landed first
     */
    private static function rehash(string $username, #[\SensitiveParameter] string $password, array $user, string $hash): ?array
    {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        // Never overwrite a reset or another login's rehash with a stale credential.
        $updated = Db::execute(
            'UPDATE `auth_user` SET `password` = :password WHERE `user_id` = :user_id AND `password` = :original_password',
            ['password' => $newHash, 'user_id' => (int)$user['user_id'], 'original_password' => $hash],
        );
        if ($updated !== 0) {
            return [$user, $newHash];
        }

        // Another login's rehash still verifies this password; a reset or a removal does not.
        $current = self::find($username);
        $currentHash = (string)($current['password'] ?? '');
        if ($current === null || ($current['user_id'] ?? null) !== $user['user_id']
            || ($current['status'] ?? '') === 'disabled' || !password_verify($password, $currentHash)
        ) {
            return null;
        }

        return [$current, $currentHash];
    }

    /** @return array<string, mixed>|null */
    private static function find(string $username): ?array
    {
        return Db::row(
            '
            SELECT
                `user_id`, `group_id`, `password`, `status`
            FROM
                `auth_user`
            WHERE
                `username` = :username AND
                `status` != :deleted',
            ['username' => $username, 'deleted' => 'deleted']
        );
    }

    public static function logout(?string $route = null): Response
    {
        $config = Config::enabled();

        Session::destroy();
        User::set(['user_id' => 0, 'group_id' => 0]);

        $path = $route !== null ? Config::redirectPath($route) : $config->logoutRedirect;

        return Response::redirect($path)->header('Cache-Control', 'no-store');
    }
}
