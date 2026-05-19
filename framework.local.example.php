<?php

declare(strict_types=1);

/*
 * framework.local.php - environment-specific overrides.
 *
 * Copy this file to one of these gitignored locations:
 *
 *   1. config/framework.local.php
 *   2. framework.local.php
 *
 * The first file found wins. Keep this file small: use it only for values that
 * differ per machine/server or must not be committed. Normal defaults and full
 * extension settings belong in config/*.php.
 *
 * Config precedence:
 *
 *   config/*.php < framework.local.php < SIMPRA_* environment variables
 *
 * The minimum valid local file is:
 *
 *   return [];
 */

return [
    /*
     * Local development defaults.
     *
     * In production, prefer environment variables from PHP-FPM/container:
     *
     *   SIMPRA_PROJECT_URL=https://example.com
     *   SIMPRA_DEBUG=0
     */
    'project' => [
        'url' => 'http://127.0.0.1:8000',
        'debug' => true,
        'allowed_hosts' => ['127.0.0.1', 'localhost'],
    ],

    /*
     * Local HTTP development only.
     *
     * Keep session.secure=true in production HTTPS, which is already the
     * committed default in config/session.php.
     */
    'session' => [
        'secure' => false,
    ],

    /*
     * Add database credentials only when the app or enabled extensions use DB.
     *
     * Production can inject these with:
     *
     *   SIMPRA_DB_DRIVER=mysql
     *   SIMPRA_DB_HOST=127.0.0.1
     *   SIMPRA_DB_PORT=3306
     *   SIMPRA_DB_NAME=framework
     *   SIMPRA_DB_USER=framework_user
     *   SIMPRA_DB_PASS=secret
     *   SIMPRA_DB_CHARSET=utf8mb4
     */
    // 'database' => [
    //     'driver' => 'mysql',
    //     'hostname' => '127.0.0.1',
    //     'port' => 3306,
    //     'database' => 'framework',
    //     'username' => 'framework_user',
    //     'password' => 'change-me',
    //     'charset' => 'utf8mb4',
    // ],
];
