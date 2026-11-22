<?php

declare(strict_types=1);

return [
    'log' => [
        'level' => 'warning',
        'rotate_daily' => true,
        'retention_days' => 14,

        // Extra context keys to redact recursively, besides built-in secret names and suffixes.
        // Configured secret strings of 8+ bytes are also stripped from messages and context values.
        // Public cookie names belong under session_cookie_name, not the secret-suffixed session_cookie.
        'redact_keys' => [],

        // Mask config secrets in dumps/JSON; independent of context-key redaction.
        'redact_secrets' => true,
    ],
];
