<?php

declare(strict_types=1);

namespace extensions\validation;

final readonly class ChoiceRules
{
    public function __construct(
        private State $state,
    ) {
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
