<?php

declare(strict_types=1);

use extensions\security\Csp;

return [
    'extensions' => [
        'security' => [
            'enabled' => true,
            'headers' => [
                // Locks down everything to same-origin by default. 'unsafe-inline' kept on style-src
                // for inline <style> blocks; remove it if your CSS is fully external.
                'Content-Security-Policy' => "default-src 'self'; "
                    . "script-src 'self' 'nonce-" . Csp::NONCE_PLACEHOLDER . "'; "
                    . "style-src 'self' 'unsafe-inline'; "
                    . "img-src 'self' data:; "
                    . "font-src 'self'; "
                    . "connect-src 'self'; "
                    . "frame-ancestors 'none'; "
                    . "base-uri 'self'; "
                    . "form-action 'self'; "
                    . "object-src 'none'",

                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',

                // Enable only on HTTPS - remove on plain HTTP. Add 'preload' only after committing
                // to HTTPS-only and submitting to https://hstspreload.org/.
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',

                'Permissions-Policy' => 'accelerometer=(), '
                    . 'autoplay=(), '
                    . 'camera=(), '
                    . 'fullscreen=(self), '
                    . 'geolocation=(), '
                    . 'gyroscope=(), '
                    . 'magnetometer=(), '
                    . 'microphone=(), '
                    . 'midi=(), '
                    . 'payment=(), '
                    . 'usb=()',

                'Cross-Origin-Opener-Policy' => 'same-origin',
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ],
        ],
    ],
];
