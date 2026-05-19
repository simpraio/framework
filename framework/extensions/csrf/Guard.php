<?php

declare(strict_types=1);

namespace extensions\csrf;

use core\Session;
use core\http\Request;

final class Guard
{
    public const string SESSION_KEY = '_csrf_token';
    public const string FIELD = '_csrf';
    public const string HEADER = 'X-CSRF-Token';

    private const array UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public static function token(): string
    {
        Config::enabled();

        $token = self::sessionToken();
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(32));
        Session::set(self::SESSION_KEY, $token);
        return $token;
    }

    public static function requiresCheck(Request $request): bool
    {
        Config::enabled();

        return in_array($request->method(), self::UNSAFE_METHODS, strict: true);
    }

    public static function valid(Request $request): bool
    {
        Config::enabled();

        $expected = self::sessionToken();
        if ($expected === '') {
            return false;
        }

        $post = $request->post(self::FIELD);
        $provided = is_string($post) ? $post : $request->header(self::HEADER);

        return is_string($provided) && hash_equals($expected, $provided);
    }

    private static function sessionToken(): string
    {
        /** @var mixed $token */
        $token = Session::get(self::SESSION_KEY);
        return is_string($token) ? $token : '';
    }
}
