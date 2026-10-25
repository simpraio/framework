<?php

declare(strict_types=1);

namespace extensions\http_client\curl;

final class Body
{
    public static function hasData(mixed $data): bool
    {
        return $data !== null && $data !== [] && $data !== '';
    }

    /** @return array<int, mixed> */
    public static function options(RequestOptions $options): array
    {
        if (!self::hasData($options->data) && !$options->raw) {
            return [];
        }

        $curl = [
            CURLOPT_POSTFIELDS => $options->raw || is_string($options->data)
                ? $options->data
                : http_build_query(is_array($options->data) ? $options->data : []),
        ];

        // CURLOPT_POSTFIELDS selects POST unless the requested method is set explicitly.
        if ($options->method !== 'POST') {
            $curl[CURLOPT_CUSTOMREQUEST] = $options->method;
        }

        return $curl;
    }
}
