<?php

declare(strict_types=1);

return [
    'extensions' => [
        'oidc-login' => [
            // Requires http-client with verified TLS and each enabled provider's hosts allowlisted.
            'enabled' => false,
            'microsoft' => [
                'enabled' => false,
                // A GUID pins one tenant; common/organizations/consumers intentionally accept more.
                'tenant' => '',
                'client_id' => '',
                'client_secret' => '',
                'redirect_uri' => '',
                'scopes' => ['openid', 'profile', 'email'],
                'session_key' => 'microsoft.user',
                'success_redirect' => '/',
                'failure_redirect' => '/',
                'allowed_groups' => [],
                'group_match' => 'any',
                // Group overage needs Graph permissions and graph.microsoft.com allowlisted.
                'graph_fallback' => true,
                'clock_skew' => 60,
                'flow_ttl' => 600,
                // Seconds a sign-in lasts before the user must sign in again; 0 keeps it for the session.
                'session_max_age' => 28_800,
            ],
            'google' => [
                'enabled' => false,
                'client_id' => '',
                'client_secret' => '',
                'redirect_uri' => '',
                'session_key' => 'google.user',
                'success_redirect' => '/',
                'failure_redirect' => '/',
                // Empty accepts any Google account; restriction checks hd, never the email suffix.
                'allowed_domains' => [],
                'clock_skew' => 60,
                'flow_ttl' => 600,
                // Seconds a sign-in lasts before the user must sign in again; 0 keeps it for the session.
                'session_max_age' => 28_800,
            ],
        ],
    ],
];
