<?php

declare(strict_types=1);

namespace extensions\security;

use core\http\Response;

/**
 * The cache headers configured among the security headers are for responses that are the same for
 * every visitor. A response that set its own, on the Response or with header(), keeps its policy,
 * unless it is bound to one visitor or one request - an error that depends on who asked, a session,
 * credentials, a cookie, a nonce - which gets no-store and no other cache header: its controller cannot
 * know that a later hook starts a session. Only a policy on the Response that already keeps every shared
 * cache out stands there.
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

        // One sent with header() keeps the configured policy off as well, but never counts as the
        // Response's own above: session_start() sends session.cache_limiter's headers the same way, and
        // with nothing configured they are not this hook's to replace.
        if ($ownPolicy || $bound
            || array_any(headers_list(), static fn(string $line): bool => self::isCacheHeader(trim(explode(':', $line, limit: 2)[0])))) {
            return;
        }
        foreach ($configured as $name => $value) {
            $response->header($name, $value);
        }
    }

    /**
     * Whether the Response's own policy already keeps every shared cache out: no-store, or a private that
     * names no fields - private="Set-Cookie" lets a shared cache keep the rest - with no header beside it
     * that a cache reads ahead of Cache-Control.
     */
    private static function keepsSharedCachesOut(Response $response): bool
    {
        foreach (Response::SHARED_CACHE_HEADERS as $name) {
            if ($name !== 'cache-control' && $name !== 'expires' && $response->getHeader($name) !== null) {
                return false;
            }
        }
        // A quoted argument becomes '=', not nothing: a comma inside it splits no directive, and pri"x"vate
        // cannot read as private. A quote left over is an unclosed one.
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
