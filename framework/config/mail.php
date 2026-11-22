<?php

declare(strict_types=1);

return [
    'extensions' => [
        'mail' => [
            'enabled'    => false,
            'transport'  => 'smtp',   // 'smtp' or 'native' (PHP mail())
            'from_email' => '',
            'from_name'  => '',

            'max_recipients'    => 100,         // recipients of one message
            'max_message_bytes' => 26_214_400,  // 25 MiB: the text, HTML and attachments, base64 encoded

            'smtp' => [
                'host'       => '',
                'port'       => 587,
                'encryption' => 'tls',  // 'tls', 'ssl', or 'none'
                'auth'       => true,
                'username'   => '',
                'password'   => '',     // use framework.local.php - never commit
                'timeout'    => 30,
                'ehlo_host'  => '',     // name announced in EHLO; empty: the machine name when valid, else localhost
            ],
        ],
    ],
];
