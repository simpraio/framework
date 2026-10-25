<?php

declare(strict_types=1);

namespace extensions\validation;

use DateTimeImmutable;

final class Validator
{
    private State $state;
    private TypedRules $typed;

    /** @param array<string, mixed> $input */
    public function __construct(array $input)
    {
        $this->state = new State($input);
        $this->typed = new TypedRules($this->state);
    }

    public function ok(): bool
    {
        return $this->state->ok();
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->state->errors();
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->state->values();
    }

    public function int(string $name, ?int $min = null, ?int $max = null, bool $optional = false): ?int
    {
        return $this->typed->int($name, $min, $max, $optional);
    }

    public function float(string $name, ?float $min = null, ?float $max = null, bool $optional = false): ?float
    {
        return $this->typed->float($name, $min, $max, $optional);
    }

    public function string(
        string $name,
        ?int $min = null,
        ?int $max = null,
        bool $trim = false,
        bool $optional = false,
    ): ?string {
        if (!$this->state->isScalarOrNull($name)) {
            return $this->state->fail($name, 'string');
        }

        $value = $this->state->rawString($name);
        $value = $trim ? trim($value) : $value;
        if ($value === '') {
            return $this->state->blank($name, $optional);
        }

        if (!$this->state->withinBounds($name, mb_strlen($value), $min, $max)) {
            return null;
        }

        return $this->state->pass($name, $value);
    }

    public function bool(string $name, bool $optional = false): ?bool
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if ($this->state->isBlank($raw)) {
            return $this->state->blank($name, $optional);
        }

        $value = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            return $this->state->fail($name, 'bool');
        }

        return $this->state->pass($name, $value);
    }

    public function pattern(string $name, string $pattern, bool $trim = true, bool $optional = false): ?string
    {
        $value = $this->string($name, trim: $trim, optional: $optional);
        if ($value === null) {
            return null;
        }

        $result = preg_match($pattern, $value);
        if ($result === false) {
            throw new \InvalidArgumentException("Invalid regex pattern: {$pattern}");
        }

        if ($result !== 1) {
            $this->state->unsetValue($name);
            return $this->state->fail($name, 'pattern');
        }

        return $value;
    }

    public function email(string $name, bool $optional = false): ?string
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if (!is_string($raw) || trim($raw) === '') {
            return $this->state->blank($name, $optional);
        }

        $value = trim($raw);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->state->fail($name, 'email');
        }

        return $this->state->pass($name, $value);
    }

    /** @param array<int, mixed> $choices */
    public function in(string $name, array $choices, bool $optional = false): mixed
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if ($this->state->isBlank($raw)) {
            return $this->state->blank($name, $optional);
        }

        $needle = is_scalar($raw) ? (string)$raw : null;
        $haystack = array_map(static fn(mixed $choice): string => (string)$choice, $choices);
        if ($needle === null || !in_array($needle, $haystack, strict: true)) {
            return $this->state->fail($name, 'in');
        }

        return $this->state->pass($name, $raw);
    }

    public function uuid(string $name, ?int $version = null, bool $optional = false): ?string
    {
        return $this->typed->uuid($name, $version, $optional);
    }

    public function datetime(string $name, string $format = 'Y-m-d H:i:s', bool $optional = false): ?DateTimeImmutable
    {
        return $this->typed->datetime($name, $format, $optional);
    }

    /** @param callable(mixed): bool $check */
    public function custom(string $name, callable $check, string $error = 'invalid', bool $optional = false): mixed
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if ($this->state->isBlank($raw)) {
            return $this->state->blank($name, $optional);
        }

        if (!$check($raw)) {
            return $this->state->fail($name, $error);
        }

        return $this->state->pass($name, $raw);
    }
}
