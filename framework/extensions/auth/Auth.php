<?php

declare(strict_types=1);

namespace extensions\auth;

use core\db\Db;
use core\http\Response;
use core\Session;
use core\tools\Format;

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

        unset($user['password']);
        $user['last_validated_at'] = time();

        $sessionKey = $config->sessionKey;

        Session::regenerate();
        Session::forget('_csrf_token');
        Session::set($sessionKey, $user);
        User::set($user);

        RateLimit::clear($username);

        Db::update('auth_user', ['last_login_at' => Format::datetime()], ['user_id' => (int)$user['user_id']]);

        return true;
    }

    public static function logout(?string $route = null): Response
    {
        $config = Config::enabled();

        Session::destroy();
        User::set(['user_id' => 0, 'group_id' => 0]);

        $path = $config->logoutRedirect;
        if ($route !== null) {
            $r = trim(string: $route, characters: '/');
            $path = $r !== '' ? '/' . $r . '/' : '/';
        }

        return Response::redirect($path);
    }
}
