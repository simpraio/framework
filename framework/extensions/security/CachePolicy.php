<?php

declare(strict_types=1);

namespace extensions\security;

use core\http\Response;

/**
 * Configured cache headers are defaults for shared pages, not visitor-bound responses.
 * Recheck after all hooks: a later hook can start a session after a controller chose its policy.
 */
final class CachePolicy
{
    public static function isCacheHeader(string $name): bool
    {
        return in_array(strtolower($name), Response::SHARED_CACHE_HEADERS, strict: true);
    }

    /** @param array<string, string> $configured the configured headers isCacheHeader() accepts */
    public static function apply(Response $response, array $configured, bool $nonced): void
    {
        $bound = $nonced || $response->cookies !== [] || $response->getHeader('Set-Cookie') !== null
            || Response::isUncacheableError($response->status) || Response::isVisitorBound();
        $ownPolicy = array_any(Response::SHARED_CACHE_HEADERS, static fn(string $name): bool => $response->getHeader($name) !== null);

        if ($bound && ($ownPolicy || $configured !== [])) {
            // Raw headers too: session_start() and setcookie() callers send theirs outside the Response.
            if (!headers_sent()) {
                foreach (Response::SHARED_CACHE_HEADERS as $name) {
                    header_remove($name);
                }
            }
            if (!self::keepsSharedCachesOut($response)) {
                foreach (Response::SHARED_CACHE_HEADERS as $name) {
                    $response->removeHeader($name);
                }
                $response->header('Cache-Control', 'no-store');
            }
            return;
        }

        // Respect raw policies too, including PHP's session cache limiter when no policy is configured.
        if ($ownPolicy || $bound
            || array_any(headers_list(), static fn(string $line): bool => self::isCacheHeader(trim(explode(':', $line, limit: 2)[0])))) {
            return;
        }
        foreach ($configured as $name => $value) {
            $response->header($name, $value);
        }
    }

    /**
     * Field-qualified private permits caching the remaining fields; targeted CDN headers can override Cache-Control.
     */
    private static function keepsSharedCachesOut(Response $response): bool
    {
        foreach (Response::SHARED_CACHE_HEADERS as $name) {
            if ($name !== 'cache-control' && $name !== 'expires' && $response->getHeader($name) !== null) {
                return false;
            }
        }
        // Keep a placeholder for quoted text so pri"x"vate cannot become a private directive.
        $policy = preg_replace('~"(?:[^"\\\\]|\\\\.)*"~s', replacement: '=', subject: $response->getHeader('Cache-Control') ?? '');
        if ($policy === null || str_contains($policy, '"')) {
            return false;
        }
        $directives = array_map(
            static fn(string $directive): string => strtolower(trim($directive)),
            explode(',', $policy),
        );

        return array_intersect(['no-store', 'private'], $directives) !== [];
    }
}
