<?php

declare(strict_types=1);

namespace core\extension;

use core\config\Config;

/**
 * Hyphenated directories map to underscored namespace segments.
 *
 * @internal
 */
final class Loader
{
    /** @return list<array{class: class-string<Boot>, file: string, config: ?string}> */
    public static function buildMap(string $dir): array
    {
        $map = [];
        foreach (self::folders($dir) as $name) {
            $configFile = "{$dir}/{$name}/Config.php";
            if (!self::isEnabled($name, $configFile)) {
                continue;
            }
            $class = 'extensions\\' . self::namespaceName($name) . '\\Boot';
            $file = "{$dir}/{$name}/Boot.php";
            if (is_file($file)) {
                require_once $file;
            }
            if (!is_subclass_of($class, Boot::class)) {
                continue;
            }
            $map[] = [
                'class' => $class,
                'file' => $file,
                'config' => is_file($configFile) ? $configFile : null,
            ];
        }

        return $map;
    }

    /**
     * @param list<array{class: class-string<Boot>, file: string, config: ?string}> $map
     * @return array{hooks: list<Hook>, contributors: list<Contributor>}
     */
    public static function instantiate(array $map): array
    {
        $hooks = [];
        $contributors = [];
        foreach ($map as $boot) {
            $class = $boot['class'];
            if (!class_exists($class, false)) {
                require_once $boot['file'];
                if ($boot['config'] !== null) {
                    require_once $boot['config'];
                }
            }

            if (!class_exists($class)) {
                continue;
            }
            $instance = new $class();
            if ($instance instanceof Bootable) {
                $instance->boot();
            }
            if ($instance instanceof Hook) {
                $hooks[] = $instance;
            }
            if ($instance instanceof Contributor) {
                $contributors[] = $instance;
            }
        }
        return ['hooks' => self::byPriority($hooks), 'contributors' => self::byPriority($contributors)];
    }

    /**
     * Ordered here rather than in the map, so a bundle built before a PRIORITY changed still runs
     * in the right order. Stable: equal priorities keep the map's folder-name order.
     *
     * @template T of object
     * @param list<T> $instances
     * @return list<T>
     */
    private static function byPriority(array $instances): array
    {
        usort($instances, static fn(object $a, object $b): int => self::priority($a) <=> self::priority($b));
        return $instances;
    }

    private static function priority(object $instance): int
    {
        return $instance instanceof Boot ? $instance::PRIORITY : Boot::PRIORITY;
    }

    /** @return list<string> */
    private static function folders(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = glob($dir . '/*', GLOB_ONLYDIR);
        return $found === false ? [] : array_map(basename(...), $found);
    }

    private static function isEnabled(string $name, string $configFile): bool
    {
        $raw = Config::extension($name);

        if (!is_file($configFile)) {
            return filter_var($raw['enabled'] ?? true, FILTER_VALIDATE_BOOL);
        }

        $class = 'extensions\\' . self::namespaceName($name) . '\\Config';
        if (!class_exists($class, false)) {
            require_once $configFile;
        }

        $config = self::config($class, $raw);
        return ($config->enabled ?? true) === true;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $raw
     */
    private static function config(string $class, array $raw): object
    {
        if (!method_exists($class, 'fromArray')) {
            return (object)['enabled' => true];
        }

        /** @var mixed $config */
        $config = $class::fromArray($raw);
        return is_object($config) ? $config : (object)['enabled' => true];
    }

    private static function namespaceName(string $folder): string
    {
        return str_replace(search: '-', replace: '_', subject: $folder);
    }
}
