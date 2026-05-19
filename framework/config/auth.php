<?php

declare(strict_types=1);

return [
    'extensions' => [
        'auth' => [
            'enabled'                    => false,
            'session_key'                => 'user.data',
            'guest_group'                => 'guest',
            'logout_redirect'            => '',
            'login_attempts'  => 5,
            'login_attempts_window'    => 900,
            'revalidate_interval'        => 60,
            'default_policy'             => 'deny',
            'guest_route'                => 'login',
            'denied_redirect'            => '',
        ],
    ],
];
