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
        );
    }
}
