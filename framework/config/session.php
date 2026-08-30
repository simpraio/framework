<?php

declare(strict_types=1);

return [
    'session' => [
        'name'      => 'SID',
        'lifetime'  => 0,
        'path'      => '/',
        'domain'    => '',
        'secure'    => true,
        'http_only' => true,
        'same_site' => 'Lax',
        'save_path' => '',
        // Cache headers PHP stamps on any response that started a session:
        // nocache | private | private_no_expire | '' (send none).
        // 'nocache' keeps a per-user page out of shared caches. Set '' only when the
        // application sets a safe Cache-Control header on every session-backed response.
        'cache_limiter' => 'nocache',
    ],
];
