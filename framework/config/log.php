<?php

declare(strict_types=1);

return [
    'log' => [
        'level' => 'warning',
        'rotate_daily' => true,
        'retention_days' => 14,

        // Context keys redacted besides the built-in ones, which always apply: crypt_key, and any key
        // ending in password, passwords, passwd, pwd, authpw, passphrase, secret, secrets, token,
        // tokens, credentials, authorization, cookie, cookies, apikey, privatekey, privatekeypem,
        // accesskey, secretkey, signingkey, hmackey, masterkey or encryptionkey. Matched recursively,
        // in arrays and objects, ignoring case and '_', '-', ' ', '.'. Every configured string of 8
        // bytes or more under such a key is also stripped from the message and every context string,
        // a logged trace included - so a key holding a public value, such as a session_cookie holding
        // the cookie's name, strips that value from every line too: name it session_cookie_name.
        'redact_keys' => [],

        // Mask config secrets in dumps/JSON; independent of context-key redaction.
        'redact_secrets' => true,
    ],
];
