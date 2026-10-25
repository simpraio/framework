<?php

declare(strict_types=1);

return [
    'log' => [
        'level' => 'warning',
        'rotate_daily' => true,
        'retention_days' => 14,

        // Recursive, case-insensitive context keys; messages and traces are not scrubbed.
        'redact_keys' => [],

        // Mask config secrets in dumps/JSON; independent of context-key redaction.
        'redact_secrets' => true,
    ],
];
