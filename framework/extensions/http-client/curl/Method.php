<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

use core\http\Request;
use InvalidArgumentException;

final class Method
{
    /**
     * A missing or empty method means POST when there is data and GET when there is not;
     * one the client does not send is refused rather than sent as something else.
     */
    public static function normalize(mixed $method, bool $hasData = false): string
    {
        if ($method === null || $method === '') {
            return $hasData ? 'POST' : 'GET';
        }

        $name = is_string($method) ? strtoupper($method) : '';
        if (!in_array($name, Request::METHODS, strict: true)) {
            throw new InvalidArgumentException('HTTP client method rejected');
        }

        return $name;
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
