<?php

declare(strict_types=1);

namespace core;

final readonly class Result
{
    private function __construct(
        public bool $ok,
        public mixed $value = null,
        public ?string $error = null,
    ) {
    }

    public static function ok(mixed $value = null): self
    {
        return new self(true, $value);
    }

    public static function fail(string $error, mixed $value = null): self
    {
        return new self(false, $value, $error);
    }

    public function failed(): bool
    {
        return !$this->ok;
    }
}
