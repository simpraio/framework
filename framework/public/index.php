<?php

declare(strict_types=1);

$core = dirname(__DIR__) . '/core';
$configuredBundleDir = getenv('SIMPRA_BUNDLE_DIR');
$bundleDir = rtrim(
    $configuredBundleDir !== false && $configuredBundleDir !== '' ? $configuredBundleDir : dirname(__DIR__) . '/cache',
    characters: '/\\'
);
$cache = $bundleDir . '/core.php';

if (is_file($cache)) {
    require $cache;
}

if (!class_exists('core\\Bundle', false)) {
    require $core . '/bootstrap.php';
    if (!is_dir($bundleDir) && !mkdir(directory: $bundleDir, permissions: 0o700, recursive: true) && !is_dir(
            $bundleDir
        )) {
        throw new RuntimeException("Cannot create bundle directory: {$bundleDir}");
    }
    core\Bundle::buildCore($core, $cache);
}

spl_autoload_register(static function (string $class): void {
    $segments = explode('\\', $class);

    // Reject non-class namespace segments before converting them to file paths.
    if (array_any(
        $segments,
        fn($segment) => preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $segment) !== 1
    )) {
        return;
    }

    // Map namespace segments to lowercased, hyphenated directory names
    // (extensions\http_client -> extensions/http-client) while preserving the class
    // file name. Inverse of core\extension\Loader's folder->namespace rule, which
    // turns hyphenated extension folders into underscored namespace segments.
    $className = array_pop($segments);
    $dir = implode('/', array_map(
        static fn(string $segment): string => strtolower(str_replace(search: '_', replace: '-', subject: $segment)),
        $segments
    ));

    $file = dirname(__DIR__) . '/' . ($dir === '' ? '' : $dir . '/') . $className . '.php';
    if (is_file($file)) {
        require $file;
    }
});

core\Kernel::boot(dirname(__DIR__))->run();
