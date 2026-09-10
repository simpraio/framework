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
            // A leading slash means the value is the path as written: '/login' redirects to
            // '/login', which is what a site whose canonical URLs carry no trailing slash wants.
            // A bare name becomes '/name/'.
            'guest_route'                => 'login',
            'denied_redirect'            => '',
        ],
    ],
];
