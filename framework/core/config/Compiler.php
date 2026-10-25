<?php

declare(strict_types=1);

namespace core\config;

use core\config\loader\Env;
use core\config\loader\Files;
use RuntimeException;

final readonly class Compiler
{
    /**
     * @param list<string> $requiredPaths dotted paths that must exist after merge
     */
    public function __construct(
        private Files $files,
        private Env $env,
        private array $requiredPaths = [],
    ) {
    }

    /**
     * Precedence: defaults < local < environment. Only defaults are bundled, never local or
     * environment overrides; credentials placed in defaults would be bundled too.
     *
     * @param array<string, mixed>|null $defaults
     * @return array<string, mixed>
     * @throws RuntimeException when a required path is missing in the merged result
     */
    public function compile(?array $defaults = null): array
    {
        $merged = self::merge($defaults ?? $this->loadDefaults(), $this->loadLocal());
        $merged = self::merge($merged, $this->env->load());

        $this->assertRequired($merged);
        return $merged;
    }

    /** @return array<string, mixed> */
    public function loadDefaults(): array
    {
        $merged = [];
        foreach ($this->files->configFiles() as $path) {
            /** @var array<string, mixed> $data */
            $data = require $path;
            $merged = self::merge($merged, $data);
        }
        return $merged;
    }

    /**
     * Maps merge recursively; lists and scalars replace. An empty array clears the value.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach (array_keys($override) as $key) {
            /** @var mixed $value */
            $value = $override[$key];

            if (
                is_array($value) && !array_is_list($value)
                && array_key_exists($key, $base) && is_array($base[$key]) && !array_is_list($base[$key])
            ) {
                $base[$key] = self::merge(Map::stringKeyed($base[$key]), Map::stringKeyed($value));
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function loadLocal(): array
    {
        $path = $this->files->localFile();
        if ($path === null) {
            return [];
        }
        /** @var array<string, mixed> */
        return require $path;
    }

    /** @param array<string, mixed> $config */
    private function assertRequired(array $config): void
    {
        $missing = [];
        foreach ($this->requiredPaths as $path) {
            if (Map::hasPath($config, $path)) {
                continue;
            }
            $missing[] = $path;
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required config paths: ' . implode(', ', $missing),
            );
        }
    }
}
