<?php

declare(strict_types=1);

namespace extensions\validation;

use DateTimeImmutable;

final readonly class FormatRules
{
    public function __construct(
        private State $state,
    ) {
    }

    public function uuid(string $name, ?int $version = null, bool $optional = false): ?string
    {
        /** @var mixed $raw */
        $raw = $this->state->raw($name);
        if (!is_string($raw) || $raw === '') {
            return $this->state->blank($name, $optional);
        }

        $v = $version !== null ? (string)$version : '1-8';
        $regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[' . $v . '][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
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

        $dt = DateTimeImmutable::createFromFormat($format, $raw);
        if ($dt === false || $dt->format($format) !== $raw) {
            return $this->state->fail($name, 'datetime');
        }

        return $this->state->pass($name, $dt);
    }
}
