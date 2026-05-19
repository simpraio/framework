# Configuration

## Config Layers

Configuration is built from three layers merged in order - each layer overrides the one before it:

```
1. defaults    config/*.php files  -  committed, cached in bundle
2. local       config/framework.local.php or framework.local.php -  never committed, always read live
3. environment SIMPRA_* variables  -  always read live
```

Layers are merged with `array_replace_recursive` - nested keys are deep-merged, so overriding one key inside a section does not wipe the rest of that section.

**What is cached:** all `config/*.php` files are bundled into `cache/config.php` on the first request. Subsequent requests load the bundle directly. **Local config and environment variables are never cached** - they are read fresh on every request so credentials and per-environment settings are never baked into committed files.

**Local file lookup:** the framework checks `config/framework.local.php` first, then `framework.local.php`. The first match wins. Add both paths to `.gitignore`.

## Project Settings

Defined in `config/app.php` under the `project` key:

```
'project' => [
    'name'          => 'SIMPRA',          // used as site name in SEO and schema
    'timezone'      => 'Europe/Prague',   // PHP timezone identifier
    'url'           => '',               // canonical base URL (e.g. https://example.com)
    'allowed_hosts' => [],              // Host header whitelist - empty disables check
    'debug'         => false,           // true exposes stack traces - never in production
],
```

Overrideable via env: `SIMPRA_PROJECT_URL`, `SIMPRA_DEBUG`.

## Route & Language Settings

Also in `config/app.php`:

```
'route' => [
    'default_module'     => 'main',  // module used when URL has 0 or 1 segments
    'default_controller' => 'info',  // controller used when URL has 0 segments
    'aliases' => [
        'enabled'   => false,         // enable DB-backed public URL aliases
        'cache_ttl' => 3600,          // alias cache lifetime in seconds
    ],
],

'language' => [
    'default'   => 'en',            // fallback language when no prefix in URL
    'available' => [],              // e.g. ['en', 'cs', 'de'] - enables prefix routing
],
```

When `language.available` is empty, no language prefix is consumed from the URL. Set it to a non-empty list to activate language prefix routing (see [Routing -> Language prefix](03-Routing.md#language-prefix)).

## Session Settings

Defined in `config/session.php`:

```
'session' => [
    'name'      => 'SID',   // cookie name
    'lifetime'  => 0,       // seconds; 0 = until browser closes
    'path'      => '/',
    'domain'    => '',      // empty = current domain only
    'secure'    => true,   // HTTPS only - set false for local HTTP development
    'http_only' => true,   // no JavaScript access
    'same_site' => 'Lax',  // 'Strict', 'Lax', or 'None'
    'save_path' => '',     // empty = PHP default (/tmp or session.save_path)
],
```

## Log Settings

Defined in `config/log.php`:

```
'log' => [
    'level'          => 'warning', // debug | info | notice | warning | error | critical
    'rotate_daily'   => true,      // create a new log file each day
    'retention_days' => 14,        // delete log files older than N days
    'redact_keys'    => [],        // context keys whose values are replaced with [REDACTED]
],
```

Log files are written to `logs/`. `redact_keys` applies recursively and case-insensitively to context arrays passed to the logger - it does not scrub exception messages or stack traces. Use `#[\SensitiveParameter]` on method parameters holding secrets to keep them out of traces.

## Database

There is no `config/database.php`. Database credentials are supplied exclusively through environment variables or the local config file - they must never be committed. The database connection is lazy: `Config::database()` throws if called before credentials are set.

```
# required - no defaults
SIMPRA_DB_DRIVER=mysql
SIMPRA_DB_HOST=127.0.0.1
SIMPRA_DB_NAME=simpra
SIMPRA_DB_USER=simpra
SIMPRA_DB_PASS=secret

# optional
SIMPRA_DB_PORT=3306      # omit for driver default (3306 for MySQL)
SIMPRA_DB_CHARSET=utf8mb4
```

Equivalently, in `config/framework.local.php`:

```
'database' => [
    'driver'   => 'mysql',
    'hostname' => '127.0.0.1',
    'database' => 'simpra',
    'username' => 'simpra',
    'password' => 'secret',
],
```

## Extension Config

Each extension reads its own section from the `extensions` config key. All settings live in `config/app.php` (or the relevant extension config file) under `extensions.{name}`:

```
'extensions' => [
    'csrf'      => ['enabled' => true],
    'ratelimit' => ['enabled' => true, 'max' => 120, 'window' => 60],
    'security'  => [
        'headers' => [
            'content-security-policy' => "default-src 'self'; script-src 'self'",
        ],
    ],
],
```

The shipped project config decides what loads. Most bundled extensions are disabled in `config/*.php`; enable only the features your app actually uses:

```
'extensions' => ['profiler' => ['enabled' => true]],
```

From inside an extension's Boot class, prefer typed config via `CoreConfig::extensionConfig(Config::NAME, Config::class)`. Use `Config::extension('name')` only when you need the raw extension array.

## Environment Variables

All `SIMPRA_*` variables map to config paths via `array_replace_recursive`. Empty or missing variables are silently skipped.

```
SIMPRA_PROJECT_URL    ->  project.url
SIMPRA_DEBUG          ->  project.debug

SIMPRA_DB_DRIVER      ->  database.driver
SIMPRA_DB_HOST        ->  database.hostname
SIMPRA_DB_PORT        ->  database.port
SIMPRA_DB_NAME        ->  database.database
SIMPRA_DB_USER        ->  database.username
SIMPRA_DB_PASS        ->  database.password
SIMPRA_DB_CHARSET     ->  database.charset

SIMPRA_BUNDLE_DIR     ->  bundle cache directory (not a config key - used by index.php)
```

Environment variables have the highest precedence - they override both defaults and the local file. Values are always strings; type coercion happens inside the config DTOs.

## Bundle Directory

On the first request the framework writes compiled bundle files to `cache/` inside the project root. Set `SIMPRA_BUNDLE_DIR` to override the location - useful in production to place bundles on a tmpfs path or outside the source tree:

```
SIMPRA_BUNDLE_DIR=/run/simpra
```

The directory must exist and be writable by the PHP process. If you use tmpfs, ensure that deployment or service startup recreates the directory after reboot - the framework will recreate the bundle files automatically on first request, but the directory itself must exist first.

After changing any `config/*.php` default (not the local file or env vars), delete the bundle cache so it is rebuilt:

```
rm cache/config.php cache/core.php cache/extensions.php
```
