<?php

declare(strict_types=1);

namespace extensions\auth;

use core\db\Db;
use core\http\Response;
use core\Session;

final class Auth
{
    /** Precomputed empty-password hash used to equalize password_verify() timing on user misses. */
    private const string DUMMY_HASH = '$2y$12$Rzce1gHVaaG3W5FSjv4Ddutc88U28fzPvQnQVh3o722CEEtmRXaaq';

    public static function login(string $username, #[\SensitiveParameter] string $password): bool
    {
        $config = Config::enabled();

        if ($username === '' || $password === '') {
            return false;
        }

        if (RateLimit::blocked($username)) {
            return false;
        }

        $user = Db::row(
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

        $passwordHash = $user === null ? self::DUMMY_HASH : (string)($user['password'] ?? '');
        $passwordValid = password_verify($password, $passwordHash);

        if ($user === null || !$passwordValid) {
            RateLimit::fail($username);
            return false;
        }

        if ($user['status'] === 'disabled') {
            RateLimit::fail($username);
            return false;
        }

        // Algorithm changes need an explicit policy; only raise an existing bcrypt cost here.
        $passwordInfo = password_get_info($passwordHash);
        if ($passwordInfo['algo'] === PASSWORD_BCRYPT
            && (int)($passwordInfo['options']['cost'] ?? 0) < PASSWORD_BCRYPT_DEFAULT_COST
        ) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            // Never overwrite a reset or another login's rehash with a stale credential.
            $updated = Db::execute(
                'UPDATE `auth_user` SET `password` = :password WHERE `user_id` = :user_id AND `password` = :original_password',
                ['password' => $newHash, 'user_id' => (int)$user['user_id'], 'original_password' => $passwordHash],
            );
            if ($updated === 0) {
                return false;
            }
            $passwordHash = $newHash;
        }

        $user = State::sessionPayload($user, $passwordHash);

        $sessionKey = $config->sessionKey;

        Session::rotate();
        Session::set($sessionKey, $user);
        User::set($user);

        RateLimit::clear($username);

        Db::execute(
            'UPDATE `auth_user` SET `last_login_at` = UTC_TIMESTAMP(6) WHERE `user_id` = :user_id',
            ['user_id' => (int)$user['user_id']],
        );

        return true;
    }

    public static function logout(?string $route = null): Response
    {
        $config = Config::enabled();

        Session::destroy();
        User::set(['user_id' => 0, 'group_id' => 0]);

        $path = $route !== null ? Config::redirectPath($route) : $config->logoutRedirect;

        return Response::redirect($path);
    }
}
