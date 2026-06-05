<?php

declare(strict_types=1);

return [
    'log' => [
        'level' => 'warning',
        'rotate_daily' => true,
        'retention_days' => 14,

        // Keys whose values are replaced with [REDACTED] in logged context arrays
        // (case-insensitive, recursive). Empty by default - opt in per project.
        // Recommended starter list:
        //   ['password', 'pwd', 'passwd', 'secret', 'api_key', 'apikey',
        //    'token', '_csrf', 'authorization', 'cookie', 'set-cookie',
        //    'private_key', 'client_secret']
        // Note: this only scrubs the context array. Message strings and exception
        // traces are the caller's responsibility - use `#[\SensitiveParameter]`
        // on parameters holding secrets.
        'redact_keys' => [],

        // Whether config DTOs that hold secrets (DB password, SMTP password)
        // mask those fields when dumped via var_dump/json_encode (see the
        // RedactsSecrets trait). Defaults true; set false only for local
        // debugging. Independent of redact_keys, which scrubs context arrays.
        'redact_secrets' => true,
    ],
];
