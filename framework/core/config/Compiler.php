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
        $merged = array_replace_recursive(
            $defaults ?? $this->loadDefaults(),
            $this->loadLocal(),
            $this->env->load(),
        );

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
            $merged = array_replace_recursive($merged, $data);
        }
        return $merged;
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
