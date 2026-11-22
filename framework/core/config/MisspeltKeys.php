<?php

declare(strict_types=1);

namespace core\config;

use RuntimeException;

/**
 * Reject likely typos without forbidding local-only product settings.
 * Compare framework sections recursively, but only immediate product-section keys:
 * nested product maps may use arbitrary deployment names rather than setting names.
 */
final class MisspeltKeys
{
    private const array FRAMEWORK_SECTIONS = ['project', 'route', 'language', 'session', 'log', 'database', 'extensions'];

    /** Key schema for the database section, which has no committed default config file. */
    private const array DATABASE = [
        'driver' => '',
        'hostname' => '',
        'port' => 0,
        'database' => '',
        'username' => '',
        'password' => '',
        'charset' => '',
        'options' => [],
        'ssl' => ['ca' => '', 'cert' => '', 'key' => '', 'verify_server_cert' => true],
        'connect_timeout' => 0,
        'read_timeout' => 0,
    ];

    /** Header names are the operator's to add, and HTTP compares them case-insensitively. */
    private const array OPEN_MAPS = ['extensions.security.headers'];

    /**
     * @param array<string, mixed> $declared the committed defaults
     * @param array<string, mixed> $layer the local layer
     */
    public static function refuse(array $declared, array $layer): void
    {
        $declared['database'] = array_replace_recursive(self::DATABASE, Map::section($declared, 'database'));

        $found = self::misspelt('', $declared, $layer, true);
        if ($found === []) {
            return;
        }

        ksort($found);
        $named = array_map(
            static fn(string $key, string $meant): string => "{$key} (did you mean {$meant}?)",
            array_keys($found),
            $found,
        );
        // Keys only: a value may be a credential.
        throw new RuntimeException(
            'Config: the local config sets keys that look like misspellings of declared keys, so the settings '
            . 'they meant would stay at their defaults: ' . implode('; ', $named)
            . '. Correct the spelling, or declare the key in a committed config file if it is meant.',
        );
    }

    /**
     * @param array<array-key, mixed> $declared
     * @param array<array-key, mixed> $values
     * @param bool $descend whether to compare the keys of the maps in $values too
     * @return array<string, string> dotted key => the dotted declared key it resembles
     */
    private static function misspelt(string $prefix, array $declared, array $values, bool $descend): array
    {
        $found = [];
        foreach (array_keys($values) as $key) {
            if (!is_string($key)) {
                continue;
            }
            $name = $prefix . $key;
            if (!array_key_exists($key, $declared)) {
                $shaped = array_filter(
                    array_keys(Map::stringKeyed($declared)),
                    static fn(string $candidate): bool => self::sameShape(
                        $values[$key],
                        $declared[$candidate],
                        $descend && !in_array($prefix . $candidate, self::OPEN_MAPS, strict: true),
                    ),
                );
                $meant = self::nearest($key, array_values($shaped));
                if ($meant !== null) {
                    $found[$name] = $prefix . $meant;
                }
                continue;
            }

            if ($descend && Map::isMap($declared[$key]) && Map::isMap($values[$key]) && !in_array($name, self::OPEN_MAPS, strict: true)) {
                // A framework section is compared at every depth, a product's section at its own keys only.
                $below = $prefix !== '' || in_array($name, self::FRAMEWORK_SECTIONS, strict: true);
                $found = [...$found, ...self::misspelt($name . '.', $declared[$key], $values[$key], $below)];
            }
        }

        return $found;
    }

    /**
     * Settings maps must share a key to be plausible typos; arbitrary-key maps need only match shape.
     * This lets a product's `blog` section coexist with `log` without hiding `sesion.secure`.
     */
    private static function sameShape(mixed $value, mixed $declared, bool $keyed): bool
    {
        if (!Map::isMap($value)) {
            return !Map::isMap($declared);
        }
        if ($declared === []) {
            return true;
        }

        return Map::isMap($declared) && (!$keyed || array_intersect_key($value, $declared) !== []);
    }

    /**
     * The closest declared key within one edit of $key, two for a key of eight characters or more,
     * ignoring case. Two swapped neighbours count as one edit.
     *
     * @param list<string> $declared
     */
    private static function nearest(string $key, array $declared): ?string
    {
        $key = strtolower($key);
        $limit = strlen($key) >= 8 ? 2 : 1;
        $best = null;
        foreach ($declared as $candidate) {
            if (abs(strlen($candidate) - strlen($key)) > $limit) {
                continue;
            }
            $lower = strtolower($candidate);
            $distance = levenshtein($key, $lower);
            if ($distance === 2 && self::isSwap($key, $lower)) {
                $distance = 1;
            }
            if ($distance <= $limit) {
                $best = $candidate;
                $limit = $distance - 1;
            }
        }

        return $best;
    }

    private static function isSwap(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $differ = array_keys(array_diff_assoc(str_split($a), str_split($b)));

        return count($differ) === 2 && $differ[1] === $differ[0] + 1
            && $a[$differ[0]] === $b[$differ[1]] && $a[$differ[1]] === $b[$differ[0]];
    }
}
