<?php

declare(strict_types=1);

namespace core\config;

use core\Bundle;
use core\config\dto\Database;
use core\config\dto\Language;
use core\config\dto\Log;
use core\config\dto\Project;
use core\config\dto\Route;
use core\config\dto\Session;
use core\config\loader\Env;
use core\config\loader\Files;
use core\Paths;
use RuntimeException;

final class Config
{
    private const array ENV_MAP = [
        'SIMPRA_DB_DRIVER' => 'database.driver',
        'SIMPRA_DB_HOST' => 'database.hostname',
        'SIMPRA_DB_PORT' => 'database.port',
        'SIMPRA_DB_NAME' => 'database.database',
        'SIMPRA_DB_USER' => 'database.username',
        'SIMPRA_DB_PASS' => 'database.password',
        'SIMPRA_DB_CHARSET' => 'database.charset',
        'SIMPRA_PROJECT_URL' => 'project.url',
        'SIMPRA_DEBUG' => 'project.debug',
    ];


    /** @var array<string, mixed> */
    private static array $compiled = [];
    private static Project $project;
    private static Route $route;
    private static Language $language;
    private static ?Database $database = null;
    private static Session $session;
    private static Log $log;
    /** @var array<string, object> */
    private static array $extensionConfigs = [];

    public static function init(Paths $paths): void
    {
        $compiler = new Compiler(
            new Files($paths),
            new Env(self::ENV_MAP),
        );

        $bundlePath = $paths->bundleDir . '/' . Bundle::CONFIG_FILE;
        if (is_file($bundlePath)) {
            /** @var array<string, mixed> $defaults */
            $defaults = require $bundlePath;
            self::hydrate($compiler, $defaults);
            return;
        }

        $defaults = $compiler->loadDefaults();
        Bundle::buildConfig($defaults, $bundlePath);
        self::hydrate($compiler, $defaults);
    }

    /** @param array<string, mixed> $defaults */
    private static function hydrate(Compiler $compiler, array $defaults): void
    {
        self::$compiled = $compiler->compile($defaults);

        self::$project = Project::fromArray(self::section('project'));
        self::$route = Route::fromArray(self::section('route'));
        self::$language = Language::fromArray(self::section('language'));
        self::$session = Session::fromArray(self::section('session'));
        self::$log = Log::fromArray(self::section('log'));
        self::$extensionConfigs = [];
    }

    public static function project(): Project
    {
        return self::$project;
    }

    public static function route(): Route
    {
        return self::$route;
    }

    public static function language(): Language
    {
        return self::$language;
    }

    public static function database(): Database
    {
        if (self::$database !== null) {
            return self::$database;
        }

        $missing = array_filter(
            ['database.driver', 'database.hostname', 'database.database', 'database.username', 'database.password'],
            static fn(string $path): bool => !self::hasPath(self::$compiled, $path),
        );

        if ($missing !== []) {
            throw new RuntimeException('Missing required config paths: ' . implode(', ', $missing));
        }

        return self::$database = Database::fromArray(self::section('database'), self::$project->timezone);
    }

    public static function session(): Session
    {
        return self::$session;
    }

    public static function log(): Log
    {
        return self::$log;
    }

    /**
     * Raw access to an extension's config section. Used by extension classes
     * to hydrate their own DTOs.
     *
     * @return array<string, mixed>
     */
    public static function extension(string $name): array
    {
        return Map::section(self::section('extensions'), $name);
    }

    /**
     * @template T of object
     * @param class-string<T> $configClass
     * @return T
     */
    public static function extensionConfig(string $name, string $configClass): object
    {
        $key = $name . ':' . $configClass;
        if (!array_key_exists($key, self::$extensionConfigs)) {
            self::$extensionConfigs[$key] = $configClass::fromArray(self::extension($name));
        }

        /** @var T */
        return self::$extensionConfigs[$key];
    }

    /**
     * @template T of object
     * @param class-string<T> $configClass
     * @return T
     */
    public static function enabledExtension(string $name, string $configClass, string $exception): object
    {
        $config = self::extensionConfig($name, $configClass);
        self::ensureEnabled($config, $exception);

        return $config;
    }

    public static function ensureEnabled(object $config, string $exception): void
    {
        if (($config->enabled ?? null) !== true) {
            throw new RuntimeException($exception);
        }
    }

    /** @return array<string, mixed> */
    private static function section(string $name): array
    {
        /** @var array<string, mixed> */
        return self::$compiled[$name] ?? [];
    }

    /** @param array<string, mixed> $config */
    private static function hasPath(array $config, string $path): bool
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
