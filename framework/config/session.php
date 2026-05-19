<?php

declare(strict_types=1);

return [
    'session' => [
        'name'      => 'SID',
        'lifetime'  => 0,
        'path'      => '/',
        'domain'    => '',
        'secure'    => true,
        'http_only' => true,
        'same_site' => 'Lax',
        'save_path' => '',
    ],
];
