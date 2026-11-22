<?php

declare(strict_types=1);

namespace core\log;

use RuntimeException;

/**
 * Configured names add to built-ins, never replace them. Suffix matching also covers $_SERVER header prefixes.
 * Key-based detection cannot identify secrets stored under a generic name such as 'value'.
 */
final class SensitiveKeys
{
    private const int MAX_DEPTH = 32;
    private const int MAX_VALUES = 5_000;
    private const int MAX_BYTES = 262_144;

    private const array DEFAULT_KEYS = ['crypt_key'];

    private const array SECRET_SUFFIXES = [
        'password', 'passwords', 'passwd', 'pwd', 'authpw', 'passphrase', 'secret', 'secrets', 'token', 'tokens',
        'credentials', 'authorization', 'cookie', 'cookies', 'apikey', 'privatekey', 'privatekeypem',
        'accesskey', 'secretkey', 'signingkey', 'hmackey', 'masterkey', 'encryptionkey',
    ];

    /**
     * @param list<string> $configured
     * @return array<string, true> lookup of the normalized default and configured keys, for matches()
     */
    public static function index(array $configured): array
    {
        $index = [];
        foreach ([...self::DEFAULT_KEYS, ...$configured] as $key) {
            $index[self::normalize($key)] = true;
        }
        unset($index['']);

        return $index;
    }

    /** @param array<string, true> $index from index() */
    public static function matches(string $key, array $index): bool
    {
        $normalized = self::normalize($key);
        if (array_key_exists($normalized, $index)) {
            return true;
        }
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every string held under a secret name in $map, at any depth, including each string of a list or
     * map kept under one: api_tokens => ['a', 'b'] gives both.
     *
     * An int or float counts only when its own key is a secret name, spelled as Cast::string() spells
     * it: password => 12345678 gives '12345678'. A number merely inside a secret-named map is a setting,
     * not a secret - cookie => ['lifetime' => 3600] gives nothing - so harmless digits are not
     * redacted from every log line. Bools and null are never collected.
     *
     * @param array<array-key, mixed> $map
     * @param array<string, true> $index from index()
     * @return list<string>
     * @throws RuntimeException when traversal or byte limits prevent complete secret collection
     */
    public static function values(array $map, array $index): array
    {
        $state = ['remaining' => self::MAX_VALUES, 'bytes' => self::MAX_BYTES, 'values' => []];
        self::collect($map, $index, false, 1, $state);

        return array_values($state['values']);
    }

    /**
     * @param array<array-key, mixed> $map
     * @param array<string, true> $index
     * @param array{remaining: int, bytes: int, values: array<array-key, string>} $state
     */
    private static function collect(array $map, array $index, bool $underSecret, int $depth, array &$state): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Log secret collection exceeded its limits');
        }
        /** @var mixed $value */
        foreach ($map as $key => $value) {
            $state['bytes'] -= strlen((string)$key);
            if (--$state['remaining'] < 0 || $state['bytes'] < 0) {
                throw new RuntimeException('Log secret collection exceeded its limits');
            }
            $named = is_string($key) && self::matches($key, $index);
            $secret = $underSecret || $named;
            if (is_array($value)) {
                self::collect($value, $index, $secret, $depth + 1, $state);
                continue;
            }
            if ($named && (is_int($value) || is_float($value))) {
                $value = (string)$value;
            }
            if ($secret && is_string($value)) {
                if (!array_key_exists($value, $state['values'])) {
                    if (strlen($value) > $state['bytes']) {
                        throw new RuntimeException('Log secret collection exceeded its limits');
                    }
                    $state['bytes'] -= strlen($value);
                    $state['values'][$value] = $value;
                }
            }
        }
    }

    private static function normalize(string $key): string
    {
        return str_replace(search: ['_', '-', ' ', '.'], replace: '', subject: strtolower(trim($key)));
    }
}
