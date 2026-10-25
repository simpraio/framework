<?php

declare(strict_types=1);

return [
    'extensions' => [
        'http-client' => [
            'enabled' => false,
            'retries' => 5,
            'retry_delay' => 1,
            'timeout' => 60,
            'connect_timeout' => 10,

            // Disabling peer/host verification requires the acknowledgment below.
            'verify_tls' => true,

            // Insecure mode requires 'I_ACCEPT_INSECURE_TLS'. Never enable it in production.
            'tls_insecure_acknowledged' => '',

            'max_response_bytes' => 10_485_760,

            // Null disables cookie files; otherwise paths must stay under this directory.
            'cookie_jar_dir' => null,

            'allowed_protocols' => ['http', 'https'],

            // Operator-only proxy; its DNS cannot honor application-side IP pins.
            'proxy' => null,

            // An enabled guard with an empty allowlist denies all outbound HTTP.
            'egress' => [
                'enabled' => true,
                'allowlist' => [
                ],
                'block_private_ips' => true,
            ],
        ],
    ],
];
