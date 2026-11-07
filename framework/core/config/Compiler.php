<?php

declare(strict_types=1);

namespace core\config;

use core\config\loader\Env;
use core\config\loader\Files;
use RuntimeException;

final readonly class Compiler
{
    public function __construct(
        private Files $files,
        private Env $env,
    ) {
    }

    /**
     * Precedence: defaults < local < environment. Only defaults are bundled, never local or
     * environment overrides; credentials placed in defaults would be bundled too.
     *
     * @param array<string, mixed>|null $defaults
     * @return array<string, mixed>
     */
    public function compile(?array $defaults = null): array
    {
        $defaults ??= $this->loadDefaults();
        // Loaded once and checked as loaded: a layer may declare a function, which a second require redeclares.
        $local = $this->loadLocal();
        MisspeltKeys::refuse($defaults, $local);

        return self::merge(self::merge($defaults, $local), $this->env->load());
    }

    /** @return array<string, mixed> */
    public function loadDefaults(): array
    {
        $merged = [];
        foreach ($this->files->configFiles() as $path) {
            $merged = self::merge($merged, self::layer($path));
        }
        return $merged;
    }

    /**
     * Lists and scalars replace, so a later layer can shrink or clear a list with []. A map an earlier
     * layer set is only ever changed key by key: [] - PHP's empty list and empty map alike - leaves it
     * alone, so a placeholder cannot wipe committed hardening, and a list or scalar in its place is refused.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override, string $path = ''): array
    {
        foreach (array_keys($override) as $key) {
            /** @var mixed $value */
            $value = $override[$key];
            /** @var mixed $current */
            $current = $base[$key] ?? null;

            if (!Map::isMap($current)) {
                $base[$key] = $value;
                continue;
            }
            if ($value === []) {
                continue;
            }
            if (!Map::isMap($value)) {
                // The type only: the value may be a credential.
                throw new RuntimeException(sprintf(
                    "Config: '%s' is a map of settings; a later layer can change its keys but not replace it with %s.",
                    $path . $key,
                    is_array($value) ? 'a list' : get_debug_type($value),
                ));
            }
            $base[$key] = self::merge(Map::stringKeyed($current), Map::stringKeyed($value), $path . $key . '.');
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function loadLocal(): array
    {
        $path = $this->files->localFile();

        return $path === null ? [] : self::layer($path);
    }

    /**
     * A layer that is not a map of sections - a forgotten `return`, a list - is refused: dropped or merged
     * under a numeric key, every setting in it would silently fall back to its default.
     *
     * @return array<string, mixed>
     */
    private static function layer(string $path): array
    {
        /** @var mixed $data */
        $data = require $path;

        if (!is_array($data) || array_any(array_keys($data), static fn(int|string $key): bool => !is_string($key))) {
            throw new RuntimeException(sprintf(
                'Config: %s must return an array keyed by section name (missing `return`?), got %s.',
                basename($path),
                is_array($data) ? 'an array with numeric keys' : get_debug_type($data),
            ));
        }

        /** @var array<string, mixed> */
        return $data;
    }
}
