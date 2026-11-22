<?php

declare(strict_types=1);

namespace core\config;

use core\config\loader\Env;
use core\config\loader\Files;
use ErrorException;
use RuntimeException;

final readonly class Compiler
{
    /**
     * Flat maps merged with their integer keys kept: a PDO constant set there never reaches the connection, and
     * Database::settings() refuses it only if it is still there to see.
     */
    private const array KEEP_INTEGER_KEYS = ['database.options', 'database.ssl'];

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
     * Replace lists and scalars; merge maps. An empty override preserves an existing map so a
     * placeholder cannot erase hardening, but clears an existing list.
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
            $base[$key] = in_array($path . $key, self::KEEP_INTEGER_KEYS, strict: true)
                ? array_replace($current, $value)
                : self::merge(Map::stringKeyed($current), Map::stringKeyed($value), $path . $key . '.');
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
     * Config::init() runs before ErrorHandler::register(), so a warning in a layer would be printed or
     * logged and the boot would go on with null for whatever it was computing. The message names the file
     * and never PHP's text, which can quote a value. Every level but the deprecations is raised while the layer
     * runs, so a php.ini that masks warnings cannot switch the check off, while a deprecation it hid is not
     * passed on to PHP's own display and log; `@` still silences its own expression.
     *
     * @return array<string, mixed>
     */
    private static function layer(string $path): array
    {
        $reporting = error_reporting();
        error_reporting($reporting | (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED));
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($path): bool {
            if ((error_reporting() & $severity) === 0 || ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                return false;
            }

            throw new ErrorException(sprintf(
                'Config: %s raised a PHP warning at %s:%d; a setting computed from it would silently be null or missing.',
                basename($path),
                basename($file),
                $line,
            ), 0, $severity, $file, $line);
        });
        try {
            /** @var mixed $data */
            $data = require $path;
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }

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
