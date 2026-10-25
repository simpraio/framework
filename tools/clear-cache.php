<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/root.php';

use core\cache\Cache;
use core\Instance;
use core\Paths;

$paths = new Paths(SIMPRA_ROOT);
Instance::init($paths->base);

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
