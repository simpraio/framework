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

    /** A float or bool has no single text form: true would read as "1" and false as absent. */
    public function isTextOrNull(string $name): bool
    {
        return !array_key_exists($name, $this->input)
            || $this->input[$name] === null
            || is_string($this->input[$name])
            || is_int($this->input[$name]);
    }

    public function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    public function blank(string $name, bool $optional): null
    {
        if (!$optional) {
            $this->fail($name, 'required');
        }

        return null;
    }

    /** A field with an error never has a value, whichever of its rules ran first. */
    public function fail(string $name, string $code): null
    {
        unset($this->values[$name]);
        $this->errors[$name] = $code;

        return null;
    }

    /**
     * @template T
     * @param T $value
     * @return T
     */
    public function pass(string $name, mixed $value): mixed
    {
        if (!array_key_exists($name, $this->errors)) {
            $this->values[$name] = $value;
        }

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
