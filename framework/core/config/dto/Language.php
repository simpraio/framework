<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\Map;
use core\http\RouteSegment;
use RuntimeException;

final readonly class Language
{
    /** @param list<string> $available */
    public function __construct(
        public string $default,
        public array $available,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $default = Cast::string($raw['default'] ?? null, 'language.default', 'en');
        $available = Map::lowerStringList($raw, 'available');
        // A language is matched against the first URL segment; a code that cannot be one never matches.
        // With no languages in URLs the default never meets one: it is only a lookup key then.
        $inUrls = $available === [] ? [] : ['language.default' => [$default], 'language.available' => $available];
        foreach ($inUrls as $key => $codes) {
            foreach ($codes as $code) {
                if (RouteSegment::normalize($code) === null) {
                    throw new RuntimeException("Config: '{$key}' must hold URL segments - letters, digits and inner hyphens - got '{$code}'");
                }
            }
        }

        return new self(strtolower($default), $available);
    }
}
