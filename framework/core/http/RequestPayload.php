<?php

declare(strict_types=1);

namespace core\http;

use core\config\Config;
use JsonException;
use Throwable;

final class RequestPayload
{
    /**
     * Fallback JSON nesting depth used when the configured value cannot be read
     * (e.g. before config is loaded). The effective limit is project.max_json_depth.
     */
    private const int DEFAULT_MAX_DEPTH = 64;

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

        try {
            // Limit JSON nesting depth to avoid cheap decode-time DoS payloads.
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, associative: true, depth: self::maxDepth(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed JSON body');
        }

        return self::array($decoded);
    }

    /**
     * Effective maximum JSON nesting depth (project.max_json_depth), with a safe
     * constant fallback so this decoder never fails to resolve a limit even before
     * config loads.
     */
    private static function maxDepth(): int
    {
        try {
            $depth = Config::project()->maxJsonDepth;
            return $depth > 0 ? $depth : self::DEFAULT_MAX_DEPTH;
        } catch (Throwable) {
            return self::DEFAULT_MAX_DEPTH;
        }
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
