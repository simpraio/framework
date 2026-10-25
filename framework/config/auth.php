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
            // Leading-slash paths stay exact; bare route names become /name/.
            'guest_route'                => 'login',
            // Appended to guest_route so a sign-in can return to the page that was asked for.
            // Empty turns it off, which a project should consider if its URLs carry anything
            // sensitive in a query string: the target reaches access logs and browser history.
            'return_param'               => 'return',
            'denied_redirect'            => '',
        ],
    ],
];
