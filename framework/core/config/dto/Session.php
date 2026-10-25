<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;

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
            sameSite: Cast::oneOf($raw['same_site'] ?? null, 'session.same_site', ['Lax', 'Strict', 'None'], 'Lax'),
            savePath: Cast::string($raw['save_path'] ?? null, 'session.save_path'),
        // Reject 'public': session-backed responses must not become shared-cache entries.
            cacheLimiter: Cast::oneOf(
                $raw['cache_limiter'] ?? null,
                'session.cache_limiter',
                ['nocache', 'private', 'private_no_expire', ''],
                'nocache',
            ),
        );
    }
}
