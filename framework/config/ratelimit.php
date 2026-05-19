<?php

declare(strict_types=1);

return [
    'extensions' => [
        'ratelimit' => [
            'enabled' => false,
            'max'     => 60,   // requests allowed per window
            'window'  => 60,   // window size in seconds
        ],
    ],
];
