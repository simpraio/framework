<?php

declare(strict_types=1);

namespace extensions\auth;

final class User
{
    private static ?object $data = null;

    public static function current(): object
    {
        Config::enabled();
        self::$data ??= State::fromSession();
        return self::$data;
    }

    public static function isAuthenticated(): bool
    {
        return self::id() > 0;
    }

    public static function isGuest(): bool
    {
        return !self::isAuthenticated();
    }

    public static function id(): int
    {
        return (int)(self::current()->user_id ?? 0);
    }

    public static function group(): ?string
    {
        return Groups::name((int)(self::current()->group_id ?? 0));
    }

    public static function inGroup(string|array $groups): bool
    {
        return in_array(self::group(), (array)$groups, strict: true);
    }

    public static function profile(?string $key = null, mixed $default = null): mixed
    {
        $user = self::current();
        if ($key === null) {
            return $user;
        }

        $data = get_object_vars($user);
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public static function set(object|array $user): void
    {
        Config::enabled();
        self::$data = is_array($user) ? (object)$user : $user;
    }

    public static function setGuest(): void
    {
        Config::enabled();
        self::$data = State::guest();
    }
}
