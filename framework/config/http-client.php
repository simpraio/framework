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

            // TLS verification. Keep true unless you have a specific reason
            // (e.g. self-signed cert in dev). When false, both peer and host
            // verification are disabled together - they should never diverge.
            // Setting false is REFUSED at startup unless tls_insecure_acknowledged
            // below is set to the exact sentinel - disabling TLS must be deliberate.
            'verify_tls' => true,

            // Required acknowledgment to allow verify_tls=false. Must equal the
            // exact string "I_ACCEPT_INSECURE_TLS"; any other value keeps the
            // insecure mode refused. Never set this in production - provide a CA
            // bundle for self-signed/internal hosts instead.
            'tls_insecure_acknowledged' => '',

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

            // Outbound HTTP proxy, e.g. 'http://proxy.internal:3128'. Operator-only:
            // it is intentionally NOT accepted as a per-request option, since a
            // request-supplied proxy would route traffic through an arbitrary host
            // and bypass the egress allowlist below. null = no proxy.
            'proxy' => null,

            // SSRF egress guard (see core\config\Egress). With enabled=true a
            // request may only target a host in allowlist. An empty allowlist
            // blocks all outbound HTTP, so add partner/API hosts explicitly.
            'egress' => [
                'enabled' => true,
                'allowlist' => [
                    // 'api.partner.example.com',
                ],
                'block_private_ips' => true,
            ],
        ],
    ],
];
