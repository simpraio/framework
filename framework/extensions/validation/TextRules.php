<?php

declare(strict_types=1);

namespace extensions\validation;

final readonly class TextRules
{
    public function __construct(
        private State $state,
    ) {
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
}
