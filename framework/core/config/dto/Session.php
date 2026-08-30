<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;

/**
 * `secure` defaults to FALSE so a plain-HTTP development host needs no configuration. The shipped
 * config/session.php sets it to true, so the default is only reachable by deleting the key - which
 * makes the session cookie sendable over plain HTTP. Treat a missing session.secure as a
 * configuration error, not a default.
 */
final readonly class Session
{
    public function __construct(
        public string $name,
        public int $lifetime,
        public string $path,
        public string $domain,
        public bool $secure,
        public bool $httpOnly,
        public string $sameSite,
        public string $savePath,
        public string $cacheLimiter = 'nocache',
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            name: Cast::string($raw['name'] ?? null, 'session.name', 'SID'),
            lifetime: Cast::int($raw['lifetime'] ?? null, 'session.lifetime'),
            path: Cast::string($raw['path'] ?? null, 'session.path', '/'),
            domain: Cast::string($raw['domain'] ?? null, 'session.domain'),
            secure: Cast::bool($raw['secure'] ?? null, 'session.secure'),
            httpOnly: Cast::bool($raw['http_only'] ?? null, 'session.http_only', true),
            // An unrecognised SameSite is emitted verbatim and then ignored by the browser,
            // silently weakening the cookie, so reject it at boot instead.
            sameSite: Cast::oneOf($raw['same_site'] ?? null, 'session.same_site', ['Lax', 'Strict', 'None'], 'Lax'),
            savePath: Cast::string($raw['save_path'] ?? null, 'session.save_path'),
            // Starting a session makes PHP stamp cache headers on the response. 'nocache' is
            // kept as the default because it is what stops a shared proxy storing a per-user
            // page; '' sends none and hands that decision to the application. 'public' is
            // deliberately rejected because it would make every session-backed response
            // eligible for storage by shared caches.
            cacheLimiter: Cast::oneOf(
                $raw['cache_limiter'] ?? null,
                'session.cache_limiter',
                ['nocache', 'private', 'private_no_expire', ''],
                'nocache',
            ),
        );
    }
}
