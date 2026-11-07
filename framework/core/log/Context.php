<?php

declare(strict_types=1);

namespace core\log;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Throwable;
use UnitEnum;

/**
 * Turns a log call's context into the JSON written after the message, with the value of every key
 * SensitiveKeys matches replaced by [REDACTED], every known secret value stripped from each key and
 * each string by Secrets, and every string cut by Text::cap().
 */
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
     * A new array, never $value written in place: an element held by reference would carry the write
     * back into the caller's variable. The context is level 1; an array or object that would be level
     * MAX_DEPTH + 1 is written as [DEPTH]. Every value counts against $budget, and past MAX_VALUES the
     * rest of each array is cut and marked [TRUNCATED] - an array referring to itself twice has 2^32
     * paths to the depth limit. An array's own entries are paid for before anything nested in them is
     * walked, so a key logged after a large list - a request id - is kept, and the list is what is cut.
     * Keys and retained string values also spend a shared byte allowance before JSON escaping;
     * numeric values and placeholder overhead remain bounded by MAX_VALUES.
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
     * $key with every known secret value stripped. A key that strips to the name of one already written
     * at this level, which overwriting would drop, is written with #2 appended, or #3 and on.
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

    /** Only string keys are matched - list items keep their values, though arrays and objects inside them are walked. */
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
