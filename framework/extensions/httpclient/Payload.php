<?php

declare(strict_types=1);

namespace extensions\httpclient;

use JsonException;

final class Payload
{
    /** @param array<string, string|list<string>> $headers */
    public static function json(array $headers, mixed $body): mixed
    {
        $contentType = $headers['content-type'] ?? '';
        if (is_array($contentType)) {
            $contentType = $contentType[0] ?? '';
        }

        if (!str_contains(strtolower($contentType), 'json')) {
            return null;
        }

        try {
            return json_decode(
                json: (string)$body,
                associative: true,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return null;
        }
    }
}
