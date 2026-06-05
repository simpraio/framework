<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

use extensions\http_client\Config;

final class Policy
{
    public static function allowedProtocols(Config $config): string
    {
        return implode(',', $config->allowedProtocols);
    }

    public static function connectTimeout(Config $config, RequestOptions $options): int
    {
        return self::positiveInt($options->connectTimeout, $config->connectTimeout);
    }

    public static function timeout(Config $config, RequestOptions $options): int
    {
        return self::positiveInt($options->timeout, $config->timeout);
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        $value = is_int($value) || is_string($value) ? (int)$value : 0;

        return $value > 0 ? $value : $default;
    }
}
