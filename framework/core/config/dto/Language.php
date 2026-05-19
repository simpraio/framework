<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\Map;

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

        return new self(strtolower($default), Map::lowerStringList($raw, 'available'));
    }
}
