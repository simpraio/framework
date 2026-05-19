<?php

declare(strict_types=1);

return [
    'extensions' => [
        'httpclient' => [
            'enabled' => false,
            'retries' => 5,
            'retry_delay' => 1,
            'timeout' => 60,
            'connect_timeout' => 10,

            // TLS verification. Keep true unless you have a specific reason
            // (e.g. self-signed cert in dev). When false, both peer and host
            // verification are disabled together - they should never diverge.
            'verify_tls' => true,

            // Hard cap on response body size. Prevents memory DoS from a
            // controlled or compromised target serving a huge response.
            // Default 10 MiB. Increase if you need to download large files.
            'max_response_bytes' => 10_485_760,

            // Cookie jar directory. When null, the `cookies` option in
            // requests is rejected (default - closes the file-write primitive).
            // To enable cookie jars, set to an absolute path of a directory
            // owned by the PHP user, and ensure callers only pass paths
            // that resolve under it.
            'cookie_jar_dir' => null,

            'allowed_protocols' => ['http', 'https'],
        ],
    ],
];
