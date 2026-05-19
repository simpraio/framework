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
    $file = dirname(__DIR__) . '/' . strtr(string: $class, from: '\\', to: '/') . '.php';
    if (is_file($file)) {
        require $file;
    }
});

core\Kernel::boot(dirname(__DIR__))->run();
