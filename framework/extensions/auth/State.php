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
                'SELECT `status` FROM `auth_user` WHERE `user_id` = :user_id',
                ['user_id' => $userId]
            );

            if ($row === null || ($row['status'] ?? '') !== 'active') {
                Session::destroy();
                return self::guest();
            }

            $data['last_validated_at'] = $now;
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
}
