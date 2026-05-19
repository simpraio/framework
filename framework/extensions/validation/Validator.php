<?php

declare(strict_types=1);

namespace extensions\validation;

use DateTimeImmutable;

final class Validator
{
    private State $state;
    private NumberRules $numbers;
    private TextRules $text;
    private ChoiceRules $choices;
    private FormatRules $formats;

    /** @param array<string, mixed> $input */
    public function __construct(array $input)
    {
        $this->state = new State($input);
        $this->numbers = new NumberRules($this->state);
        $this->text = new TextRules($this->state);
        $this->choices = new ChoiceRules($this->state);
        $this->formats = new FormatRules($this->state);
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
        return $this->numbers->int($name, $min, $max, $optional);
    }

    public function float(string $name, ?float $min = null, ?float $max = null, bool $optional = false): ?float
    {
        return $this->numbers->float($name, $min, $max, $optional);
    }

    public function string(
        string $name,
        ?int $min = null,
        ?int $max = null,
        bool $trim = false,
        bool $optional = false,
    ): ?string {
        return $this->text->string($name, $min, $max, $trim, $optional);
    }

    public function bool(string $name, bool $optional = false): ?bool
    {
        return $this->choices->bool($name, $optional);
    }

    public function pattern(string $name, string $pattern, bool $trim = true, bool $optional = false): ?string
    {
        return $this->text->pattern($name, $pattern, $trim, $optional);
    }

    public function email(string $name, bool $optional = false): ?string
    {
        return $this->text->email($name, $optional);
    }

    /** @param array<int, mixed> $choices */
    public function in(string $name, array $choices, bool $optional = false): mixed
    {
        return $this->choices->in($name, $choices, $optional);
    }

    public function uuid(string $name, ?int $version = null, bool $optional = false): ?string
    {
        return $this->formats->uuid($name, $version, $optional);
    }

    public function datetime(string $name, string $format = 'Y-m-d H:i:s', bool $optional = false): ?DateTimeImmutable
    {
        return $this->formats->datetime($name, $format, $optional);
    }

    /** @param callable(mixed): bool $check */
    public function custom(string $name, callable $check, string $error = 'invalid', bool $optional = false): mixed
    {
        return $this->choices->custom($name, $check, $error, $optional);
    }
}
