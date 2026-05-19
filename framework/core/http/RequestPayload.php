<?php

declare(strict_types=1);

namespace core\http;

final class RequestPayload
{
    public static function isJson(?string $contentType): bool
    {
        return stripos(haystack: $contentType ?? '', needle: 'application/json') !== false;
    }

    /** @return array<string, mixed> */
    public static function json(string $rawBody, ?string $contentType): array
    {
        if ($rawBody === '' || !self::isJson($contentType)) {
            return [];
        }

        return self::array(json_decode($rawBody, associative: true));
    }

    /** @return array<string, mixed> */
    private static function array(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            throw new HttpException(400, 'Malformed JSON body');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
