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

            // Extra query keys to redact, besides log.redact_keys and built-ins.
            // Secret values under log.redact_keys and the built-in names are stripped from messages and
            // traces; a stored URL gets query-name redaction only.
            'redact_keys'    => [],
        ],
    ],
];
