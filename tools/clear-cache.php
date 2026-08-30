<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/root.php';

spl_autoload_register(static function (string $class): void {
    $file = SIMPRA_ROOT . '/' . strtr($class, '\\', '/') . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use core\cache\Cache;
use core\Instance;
use core\Paths;

$paths = new Paths(SIMPRA_ROOT);
Instance::init($paths->base);

Cache::delete('config');
Cache::delete('extensions');

foreach ([
    'tpl.',
    'asset.version.',
    'seo.',
    'translation.',
    'registry.',
] as $prefix) {
    Cache::deletePrefix($prefix);
}

echo 'Cleared framework derived cache keys.' . PHP_EOL;
