<?php

declare(strict_types=1);

namespace core\log;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Throwable;
use UnitEnum;

final readonly class Context
{
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
    private const int MAX_DEPTH = 32;
    private const int MAX_VALUES = 5_000;
    private const int MAX_BYTES = 262_144;

    /** @var array<string, true> */
    private array $sensitiveKeys;

    /** @param list<string> $redactKeys keys to redact besides SensitiveKeys' built-in ones */
    public function __construct(
        array $redactKeys,
        private Secrets $secrets,
    ) {
        $this->sensitiveKeys = SensitiveKeys::index($redactKeys);
    }

    /** @param array<string, mixed> $context */
    public function encode(array $context): string
    {
        $budget = self::MAX_VALUES;
        $bytes = self::MAX_BYTES;
        $encoded = json_encode($this->scrub($context, 1, $budget, $bytes), self::JSON_FLAGS);

        return $encoded !== false ? $encoded : '{}';
    }

    /**
     * Copy rather than mutate: referenced elements must not write back into caller data.
     * Reserve sibling entries before descending so a large child cannot consume their budget.
     * Shared value and byte budgets bound recursive graphs, which a depth limit alone cannot.
     *
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private function scrub(array $value, int $depth, int &$budget, int &$bytes, ?object $owner = null): array
    {
        $stored = $owner === null ? [] : get_mangled_object_vars($owner);
        $paid = min(count($value), max(0, $budget));
        $budget -= $paid;

        $scrubbed = [];
        $written = 0;
        /** @var mixed $item */
        foreach ($value as $key => $item) {
            if ($owner !== null && is_string($key) && str_contains($key, "\0")) {
                continue;
            }
            $bytes -= strlen((string)$key);
            if ($written++ === $paid || $bytes < 0) {
                $scrubbed[] = '[TRUNCATED]';
                break;
            }
            if (array_key_exists($key, $stored)
                && !(is_string($key) && SensitiveKeys::matches($key, $this->sensitiveKeys))) {
                /** @var mixed $item */
                // @mago-expect analysis:string-member-selector,analysis:ambiguous-object-property-access -- stored public keys only; normal reads preserve property hooks.
                $item = $owner?->{$key};
            }
            $scrubbed[$this->name($key, $scrubbed)] = $this->item($key, $item, $depth, $budget, $bytes);
        }
        return $scrubbed;
    }

    /**
     * Disambiguate redacted keys so collisions cannot overwrite another context entry.
     *
     * @param array<int|string, mixed> $written
     */
    private function name(int|string $key, array $written): int|string
    {
        if (!is_string($key)) {
            return $key;
        }
        $name = $this->secrets->strip($key);
        $unique = $name;
        for ($suffix = 2; array_key_exists($unique, $written); $suffix++) {
            $unique = $name . '#' . $suffix;
        }

        return $unique;
    }

    private function item(int|string $key, mixed $item, int $depth, int &$budget, int &$bytes): mixed
    {
        if (is_string($key) && SensitiveKeys::matches($key, $this->sensitiveKeys)) {
            return $this->text('[REDACTED]', $bytes);
        }
        // Checked before reading properties or invoking a serializer.
        if ($depth >= self::MAX_DEPTH && (is_array($item) || is_object($item))) {
            return $this->text('[DEPTH]', $bytes);
        }

        return match (true) {
            is_object($item) => $this->object($item, $depth, $budget, $bytes),
            is_array($item) => $this->scrub($item, $depth + 1, $budget, $bytes),
            is_string($item) => $this->text($item, $bytes),
            default => $item,
        };
    }

    private function object(object $value, int $depth, int &$budget, int &$bytes): mixed
    {
        try {
            if ($value instanceof JsonSerializable) {
                /** @var mixed $plain */
                $plain = $value->jsonSerialize();
                if ($plain !== $value) {
                    return $this->item(0, $plain, $depth + (int)is_object($plain), $budget, $bytes);
                }
            }
            if ($value instanceof UnitEnum) {
                $encoded = json_encode($value, self::JSON_FLAGS);
                return $this->item(0, json_decode($encoded === false ? 'null' : $encoded, associative: true), $depth, $budget, $bytes);
            }
            $properties = get_mangled_object_vars($value);
            // A native copy supplies date fields without invoking subclass serialization.
            if ($value instanceof DateTimeInterface) {
                $properties += DateTimeImmutable::createFromInterface($value)->__serialize();
            }
            return (object)$this->scrub($properties, $depth + 1, $budget, $bytes, $value);
        } catch (Throwable) {
            return $this->text($value::class, $bytes);
        }
    }

    private function text(string $value, int &$bytes): string
    {
        // Strip before either cut, so a secret crossing a boundary leaves no prefix behind.
        $text = Text::cap($this->secrets->strip($value));
        $bytes -= strlen($text);
        if ($bytes < 0) {
            return '[TRUNCATED]';
        }

        return $text;
    }
}
