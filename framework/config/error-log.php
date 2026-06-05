<?php

declare(strict_types=1);

return [
    'extensions' => [
        'error-log' => [
            'enabled'        => false,
            'retention_days' => 30,

            // Whether to persist `getTraceAsString()` for each exception.
            // Set false in compliance-sensitive deployments where any incident
            // artifact is treated as sensitive (PCI/HIPAA/etc.). Trace argument
            // leakage is also mitigated at the source via #[\SensitiveParameter].
            'store_trace'    => true,

            // Query-parameter names whose values are replaced with [REDACTED]
            // in the stored `url` column (case-insensitive). Empty by default.
            // Recommended starter list:
            //   ['token', 'access_token', 'refresh_token', 'code',
            //    'password', 'secret', 'api_key', 'apikey',
            //    'authorization', 'session', 'sid']
            // Note: this only scrubs URL query parameters. Path segments,
            // exception messages, and trace bodies are not pattern-matched.
            'redact_keys'    => [],
        ],
    ],
];
