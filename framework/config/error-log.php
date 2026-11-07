<?php

declare(strict_types=1);

return [
    'extensions' => [
        'error-log' => [
            'enabled'        => false,

            // Rows older than this many days are deleted once a day; 0 keeps every row.
            'retention_days' => 30,

            // Traces may contain sensitive data; disable storage where required.
            'store_trace'    => true,

            // Query keys redacted besides log.redact_keys and its built-in ones, matched the same way; paths, messages and traces are not scrubbed.
            'redact_keys'    => [],
        ],
    ],
];
