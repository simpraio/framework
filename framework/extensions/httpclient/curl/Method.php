<?php

declare(strict_types=1);

namespace extensions\httpclient\curl;

final class Method
{
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    public static function normalize(mixed $method): string
    {
        $method = strtoupper((string)$method);

        return in_array($method, self::METHODS, strict: true) ? $method : 'GET';
    }

    /** @return array<int, mixed> */
    public static function options(string $method): array
    {
        if (in_array($method, ['GET', 'POST'], strict: true)) {
            return [];
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        return $options;
    }
}
