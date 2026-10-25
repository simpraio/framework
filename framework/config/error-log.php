<?php

declare(strict_types=1);

return [
    'extensions' => [
        'error-log' => [
            'enabled'        => false,
            'retention_days' => 30,

            // Traces may contain sensitive data; disable storage where required.
            'store_trace'    => true,

            // Added to log.redact_keys. Case-insensitive query keys only; paths, messages, and traces are not scrubbed.
            'redact_keys'    => [],
        ],
    ],
];
