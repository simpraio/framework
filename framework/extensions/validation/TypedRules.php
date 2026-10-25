<?php

declare(strict_types=1);

namespace extensions\validation;

use core\tools\IntegerRange;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TypedRules
{
    public function __construct(private State $state)
    {
    }

    public function int(string $name, ?int $min = null, ?int $max = null, bool $optional = false): ?int
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if ($this->state->isBlank($raw)) {
            return $this->state->blank($name, $optional);
        }

        if (!is_int($raw) && (!is_string($raw) || !IntegerRange::fits($raw))) {
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

        // NAN defeats both minimum and maximum comparisons.
        if (!is_finite($value)) {
            return $this->state->fail($name, 'float');
        }

        if (!$this->state->withinBounds($name, $value, $min, $max)) {
            return null;
        }

        return $this->state->pass($name, $value);
    }

    public function uuid(string $name, ?int $version = null, bool $optional = false): ?string
    {
        // The version becomes a regex character class: 12 would mean "1 or 2", not an error.
        if ($version !== null && ($version < 1 || $version > 8)) {
            throw new InvalidArgumentException('UUID version must be between 1 and 8.');
        }

        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if (!is_string($raw) || $raw === '') {
            return $this->state->blank($name, $optional);
        }

        $v = $version !== null ? (string)$version : '1-8';
        $regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[' . $v . '][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
        if (preg_match($regex, $raw) !== 1) {
            return $this->state->fail($name, 'uuid');
        }

        return $this->state->pass($name, strtolower($raw));
    }

    public function datetime(string $name, string $format = 'Y-m-d H:i:s', bool $optional = false): ?DateTimeImmutable
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if (!is_string($raw) || $raw === '') {
            return $this->state->blank($name, $optional);
        }

        // createFromFormat throws for a NUL instead of returning false.
        if (str_contains($raw, "\0")) {
            return $this->state->fail($name, 'datetime');
        }

        $dt = DateTimeImmutable::createFromFormat($format, $raw);
        if ($dt === false || $dt->format($format) !== $raw) {
            return $this->state->fail($name, 'datetime');
        }

        return $this->state->pass($name, $dt);
    }
}
