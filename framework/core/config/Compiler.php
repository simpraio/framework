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
     * Loads defaults < local < env, merges them, validates required paths.
     *
     * Pass $defaults to skip re-reading config/*.php (e.g. when they
     * came from a compiled bundle). Local file and env are always read live -
     * they hold credentials and per-environment overrides that must not be cached.
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
     * Deep-merge $override onto $base for layered config.
     *
     * Associative arrays (maps) merge recursively, so a later layer can override a
     * single nested key without restating the whole section. Lists and scalars are
     * REPLACED wholesale: an override list is authoritative, so a local/env layer can
     * shrink or clear a default list (e.g. egress allowlist, log.redact_keys, CORS
     * origins) — which array_replace_recursive cannot do, since it overlays lists by
     * index and leaves stale tail entries behind. An empty array override clears the value.
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
            if (self::has($config, $path)) {
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

    /** @param array<string, mixed> $config */
    private static function has(array $config, string $path): bool
    {
        $value = $config;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            /** @var mixed $value */
            $value = $value[$key];
        }
        return true;
    }
}
