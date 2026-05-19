<?php

declare(strict_types=1);

namespace extensions\validation;

final readonly class NumberRules
{
    public function __construct(
        private State $state,
    ) {
    }

    public function int(string $name, ?int $min = null, ?int $max = null, bool $optional = false): ?int
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if ($this->state->isBlank($raw)) {
            return $this->state->blank($name, $optional);
        }

        if (!is_int($raw) && (!is_string($raw) || preg_match('/^-?\d+$/', $raw) !== 1)) {
            return $this->state->fail($name, 'int');
        }

        $value = (int)$raw;
        if (!$this->state->withinBounds($name, $value, $min, $max)) {
            return null;
        }

        return $this->state->pass($name, $value);
    }

    public function float(string $name, ?float $min = null, ?float $max = null, bool $optional = false): ?float
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if ($this->state->isBlank($raw)) {
            return $this->state->blank($name, $optional);
        }

        if (!is_numeric($raw)) {
            return $this->state->fail($name, 'float');
        }

        $value = (float)$raw;
        if (!$this->state->withinBounds($name, $value, $min, $max)) {
            return null;
        }

        return $this->state->pass($name, $value);
    }
}
