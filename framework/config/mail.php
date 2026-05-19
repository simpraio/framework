<?php

declare(strict_types=1);

return [
    'extensions' => [
        'mail' => [
            'enabled'    => false,
            'transport'  => 'smtp',   // 'smtp' or 'native' (PHP mail())
            'from_email' => '',
            'from_name'  => '',

            'smtp' => [
                'host'       => '',
                'port'       => 587,
                'encryption' => 'tls',  // 'tls', 'ssl', or 'none'
                'auth'       => true,
                'username'   => '',
                'password'   => '',     // use framework.local.php or env - never commit
                'timeout'    => 30,
            ],
        ],
    ],
];
