<?php

declare(strict_types=1);

/**
 * Deletes the framework's derived cache keys from the APCu of the process running this script.
 * APCu is per process family: run from the CLI, this never reaches the web server's entries -
 * reload PHP-FPM or Apache for those. Compiled bundle files are not touched either.
 */

// core/ sits beside tools/ in a project, under framework/ in a framework checkout. Joined with the
// platform separator, as dirname() gives public/index.php its base: the keyspace hashes the string.
$base = is_dir(dirname(__DIR__) . '/framework/core') ? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'framework' : dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($base): void {
    $segments = explode('\\', $class);
    if (array_any($segments, static fn(string $segment): bool => preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $segment) !== 1)) {
        return;
    }

    $className = array_pop($segments);
    $dir = implode('/', array_map(static fn(string $segment): string => strtolower(str_replace(search: '_', replace: '-', subject: $segment)), $segments));
    $file = $base . '/' . ($dir === '' ? '' : $dir . '/') . $className . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// The keyspace is derived from the base path, so it must be the one public/index.php boots with.
core\Instance::init((new core\Paths($base))->base);

foreach ([
    'tpl.',
    'asset.version.',
    'aliases.',
    'seo.',
    'translation.',
    'registry.',
] as $prefix) {
    core\cache\Cache::deletePrefix($prefix);
}

echo (function_exists('apcu_enabled') && apcu_enabled()
    ? 'Cleared framework derived cache keys from this process\'s APCu.'
    : 'APCu is not enabled for this process; nothing to clear.')
    . ' The web server\'s APCu is separate: reload PHP-FPM or Apache to clear it.' . PHP_EOL;
