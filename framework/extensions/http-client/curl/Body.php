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
        // raw only says how data is sent, so without data a raw request is framed like any other.
        if (!self::hasData($options->data)) {
            // Without it cURL sends a bodiless POST chunked and a PUT or PATCH with no length at all.
            return in_array($options->method, ['POST', 'PUT', 'PATCH'], strict: true) ? [CURLOPT_POSTFIELDS => ''] : [];
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
