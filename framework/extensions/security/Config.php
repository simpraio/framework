<?php

declare(strict_types=1);

namespace extensions\security;

use core\config\Cast;
use core\config\Map;
use core\http\HeaderField;
use RuntimeException;

final readonly class Config
{
    public const string NAME = 'security';

    /** @param array<string, string> $headers */
    public function __construct(
        public bool $enabled,
        public array $headers,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: Cast::bool($raw['enabled'] ?? null, 'extensions.security.enabled', true),
            headers: self::headers($raw),
        );
    }

    /**
     * Refused as a config error that names the key, not left for Response::header() to reject without saying where it came from.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private static function headers(array $raw): array
    {
        $headers = array_filter(Map::stringMap($raw, 'headers'), static fn(string $value): bool => $value !== '');

        foreach ($headers as $name => $value) {
            if (!HeaderField::isName($name)) {
                throw new RuntimeException(
                    "Config: 'extensions.security.headers' has an invalid header name "
                    . json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
                    . "; a name is letters, digits and !#$%&'*+-.^_`|~ only.",
                );
            }
            if (!HeaderField::isValue($value)) {
                throw new RuntimeException("Config: 'extensions.security.headers.{$name}' contains CR, LF or NUL.");
            }
        }

        return $headers;
    }
}
