<?php

declare(strict_types=1);

namespace core\extension;

use core\config\Config;
use core\tools\Silently;
use RuntimeException;

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
        // Bundle disabled extensions too: local and environment overrides decide enablement per request.
        foreach (self::folders($dir) as $name) {
            $configFile = "{$dir}/{$name}/Config.php";
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
            if (!self::isEnabled(basename(dirname($boot['file'])), $boot['config'])) {
                continue;
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
     * Read priorities at runtime, not from the bundle; equal priorities keep folder-name order.
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
        // scandir, not glob: [ ] * ? in the install path are ordinary characters.
        $names = is_dir($dir) ? Silently::call(static fn(): array|false => scandir($dir)) : [];
        if ($names === false) {
            throw new RuntimeException("Extensions: cannot list the extensions directory {$dir}.");
        }

        $folders = array_values(array_filter(
            $names,
            static fn(string $name): bool => !str_starts_with($name, '.') && is_dir($dir . '/' . $name),
        ));
        sort($folders, SORT_STRING);

        return $folders;
    }

    private static function isEnabled(string $name, ?string $configFile): bool
    {
        $raw = Config::extension($name);

        if ($configFile === null) {
            return filter_var($raw['enabled'] ?? true, FILTER_VALIDATE_BOOL);
        }

        $class = 'extensions\\' . self::namespaceName($name) . '\\Config';
        if (!class_exists($class, false)) {
            require_once $configFile;
        }
        // A Config.php that declares no Config class leaves the extension enabled, as one without fromArray() does.
        if (!class_exists($class, false)) {
            return true;
        }

        $config = self::config($name, $class);
        return ($config->enabled ?? true) === true;
    }

    /**
     * Share the hooks' config cache so enablement checks do not construct a second Config.
     *
     * @param class-string $class
     */
    private static function config(string $name, string $class): object
    {
        if (!method_exists($class, 'fromArray')) {
            return (object)['enabled' => true];
        }

        return Config::extensionConfig($name, $class);
    }

    private static function namespaceName(string $folder): string
    {
        return str_replace(search: '-', replace: '_', subject: $folder);
    }
}
