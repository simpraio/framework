<?php

declare(strict_types=1);

namespace extensions\validation;

final class State
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @param array<string, mixed> $input */
    public function __construct(
        private readonly array $input,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    public function raw(string $name): mixed
    {
        return $this->input[$name] ?? null;
    }

    public function rawString(string $name): string
    {
        return (string)($this->input[$name] ?? '');
    }

    public function isScalarOrNull(string $name): bool
    {
        return !array_key_exists($name, $this->input)
            || $this->input[$name] === null
            || is_scalar($this->input[$name]);
    }

    public function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    public function blank(string $name, bool $optional): null
    {
        if (!$optional) {
            $this->errors[$name] = 'required';
        }

        return null;
    }

    public function fail(string $name, string $code): null
    {
        $this->errors[$name] = $code;

        return null;
    }

    public function unsetValue(string $name): void
    {
        unset($this->values[$name]);
    }

    /**
     * @template T
     * @param T $value
     * @return T
     */
    public function pass(string $name, mixed $value): mixed
    {
        $this->values[$name] = $value;

        return $value;
    }

    public function withinBounds(string $name, int|float $value, int|float|null $min, int|float|null $max): bool
    {
        if ($min !== null && $value < $min) {
            $this->fail($name, 'min');
            return false;
        }

        if ($max !== null && $value > $max) {
            $this->fail($name, 'max');
            return false;
        }

        return true;
    }
}
