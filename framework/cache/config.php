<?php

declare(strict_types=1);

return array (
  'project' => 
  array (
    'name' => 'SIMPRA',
    'timezone' => 'Europe/Prague',
    'url' => '',
    'allowed_hosts' => 
    array (
    ),
    'debug' => false,
  ),
  'route' => 
  array (
    'default_module' => 'main',
    'default_controller' => 'info',
    'aliases' => 
    array (
      'enabled' => false,
      'cache_ttl' => 3600,
    ),
  ),
  'language' => 
  array (
    'default' => 'en',
    'available' => 
    array (
    ),
  ),
  'extensions' => 
  array (
    'auth' => 
    array (
      'enabled' => false,
      'session_key' => 'user.data',
      'guest_group' => 'guest',
      'logout_redirect' => '',
      'login_attempts' => 5,
      'login_attempts_window' => 900,
      'revalidate_interval' => 60,
      'default_policy' => 'deny',
      'guest_route' => 'login',
      'denied_redirect' => '',
    ),
    'csrf' => 
    array (
      'enabled' => false,
    ),
    'errorlog' => 
    array (
      'enabled' => false,
      'retention_days' => 30,
      'store_trace' => true,
      'redact_keys' => 
      array (
      ),
    ),
    'events' => 
    array (
      'enabled' => false,
    ),
    'flash' => 
    array (
      'enabled' => false,
    ),
    'httpclient' => 
    array (
      'enabled' => false,
      'retries' => 5,
      'retry_delay' => 1,
      'timeout' => 60,
      'connect_timeout' => 10,
      'verify_tls' => true,
      'max_response_bytes' => 10485760,
      'cookie_jar_dir' => NULL,
      'allowed_protocols' => 
      array (
        0 => 'http',
        1 => 'https',
      ),
    ),
    'mail' => 
    array (
      'enabled' => false,
      'transport' => 'smtp',
      'from_email' => '',
      'from_name' => '',
      'smtp' => 
      array (
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        'username' => '',
        'password' => '',
        'timeout' => 30,
      ),
    ),
    'profiler' => 
    array (
      'enabled' => false,
    ),
    'ratelimit' => 
    array (
      'enabled' => false,
      'max' => 60,
      'window' => 60,
    ),
    'registry' => 
    array (
      'enabled' => false,
      'cache_ttl' => 60,
    ),
    'security' => 
    array (
      'enabled' => true,
      'headers' => 
      array (
        'Content-Security-Policy' => 'default-src \'self\'; script-src \'self\' \'nonce-{_csp_nonce}\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; font-src \'self\'; connect-src \'self\'; frame-ancestors \'none\'; base-uri \'self\'; form-action \'self\'; object-src \'none\'',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Permissions-Policy' => 'accelerometer=(), autoplay=(), camera=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
      ),
    ),
    'seo' => 
    array (
      'enabled' => false,
      'defaults' => 
      array (
        'title' => '',
        'description' => '',
      ),
    ),
    'translation' => 
    array (
      'enabled' => false,
      'cache_ttl' => 3600,
    ),
  ),
  'log' => 
  array (
    'level' => 'warning',
    'rotate_daily' => true,
    'retention_days' => 14,
    'redact_keys' => 
    array (
    ),
  ),
  'session' => 
  array (
    'name' => 'SID',
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'http_only' => true,
    'same_site' => 'Lax',
    'save_path' => '',
  ),
);
