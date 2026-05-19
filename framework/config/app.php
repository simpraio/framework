<?php

declare(strict_types=1);

return [
    'project' => [
        'name' => 'SIMPRA',
        'timezone' => 'Europe/Prague',
        'url' => '',
        'allowed_hosts' => [],
        'debug' => false,
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
