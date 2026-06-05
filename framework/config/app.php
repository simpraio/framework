<?php

declare(strict_types=1);

return [
    'project' => [
        'name' => 'SIMPRA',
        'timezone' => 'Europe/Prague',
        'url' => '',
        'allowed_hosts' => [],
        'debug' => false,

        // Maximum JSON nesting depth accepted when decoding request bodies.
        // Bounds CPU/stack cost of decoding a deeply-nested body that is small
        // enough to slip under the byte limit (a cheap DoS vector). Default 64.
        'max_json_depth' => 64,
    ],
    'route' => [
        'default_module' => 'main',
        'default_controller' => 'info',
        'aliases' => [
            'enabled' => false,
            'cache_ttl' => 3600,
        ],
    ],
    'language' => [
        'default' => 'en',
        'available' => [],
    ],
];
