# Configuration

## Config Layers

Configuration is built from three layers merged in order - each layer overrides the one before it:

```
1. defaults    config/*.php files  -  committed, cached in bundle
2. local       config/framework.local.php or framework.local.php -  never committed, always read live
3. environment SIMPRA_* variables  -  always read live
```

Layers are deep-merged: nested maps (associative arrays) merge recursively, so overriding one key inside a section does not wipe the rest of that section. **Lists are replaced wholesale, not merged by index** - a later layer's list is authoritative, so a local or environment layer can shrink or clear a default list (e.g. an egress allowlist) rather than only overwriting it element-by-element. `log.redact_keys` is the exception in effect: it only adds to built-in keys that no layer can remove (see [Log Settings](#log-settings)).

**`[]` clears a list but never a map.** PHP cannot tell an empty list from an empty map, so the earlier layer decides: over a list (`'allowed_hosts' => []`, `'allowlist' => []`) it clears the list; over a map (`'session' => []`, `'extensions' => ['security' => []]`) it changes nothing, so a placeholder left in a local file cannot wipe the committed settings of that section. A map is changed key by key only: replacing one with a scalar, `null` or a list - `'session' => null`, `'security' => false` - stops the boot with a config error naming the key (never the value). To turn something off, set its own key: `'enabled' => false` for an extension, `''` for a single security header.

**What is cached:** all `config/*.php` files are bundled into `cache/config.php` on the first request. Subsequent requests load the bundle directly. **Local config and environment variables are never cached** - they are read fresh on every request so credentials and per-environment settings are never baked into committed files.

**Local file lookup:** the framework checks `config/framework.local.php` first, then `framework.local.php`. The first match wins. Add both paths to `.gitignore`.

**Every layer file returns a map of sections:** `return ['project' => [...], 'session' => [...]];`. A file that returns anything else - a list, a string, nothing because the `return` is missing - stops the boot with a config error naming the file, instead of being dropped or merged under a numeric key while every setting in it silently falls back to its default. An empty `return [];` is a valid local file with no overrides.

**A misspelt local key stops the boot.** Nothing reads a key the framework does not know, so `project.allowed_host` would leave the Host check off and `extensions.csrf.enabeld` would leave CSRF off, silently. A local key that is within one edit of a declared key at the same place - two for a key of eight characters or more; letter case is ignored, and two swapped neighbours count as one edit - stops the boot with one config error naming every such key and the key it resembles, never a value. Declared means set by a committed `config/*.php` file, plus the `database` keys, which have no committed file. A key that resembles none is not judged, so credentials and a product's own settings can stay out of committed config. Nor is a key shaped unlike the one it resembles: a map is judged against a declared map only when the two share at least one key, so a product's local-only `blog`, `routes` or `sessions` section boots beside `log`, `route` and `session`, while `'sesion' => ['secure' => false]` is refused. Where the keys are not settings - header names, the maps inside a product's own section, and a declared empty array such as `database.options` - any map near the key is judged. A scalar or a list is judged only against a key that is not a map, and `[]` near a map is not judged, since it would change nothing even spelt right. The framework's own sections (`project`, `route`, `language`, `session`, `log`, `database`, `extensions`) are compared at every depth, except the names under `extensions.security.headers`, which are yours to add; a product's own section is compared at its own keys only. If a flagged key is meant, declare it in a committed config file. The check runs against the bundled defaults, so clear the bundle after changing `config/*.php` (see [Bundle Directory](#bundle-directory)). Environment variables map to fixed keys and are not checked.

Spelling checks compare string keys only, never integer option identifiers or list indexes.
This does not change which keys a configuration DTO accepts: `database.options` still passes
only string keys through to PDO.

## Project Settings

Defined in `config/app.php` under the `project` key:

```
'project' => [
    'name'          => 'SIMPRA',          // used as site name in SEO and schema
    'timezone'      => 'Europe/Prague',   // PHP timezone identifier
    'url'           => '',               // canonical base URL (e.g. https://example.com)
    'allowed_hosts' => [],              // Host header whitelist - empty disables check
    'debug'         => false,           // true exposes stack traces - never in production
    'max_json_depth' => 64,             // JSON request body nesting limit
],
```

Overrideable via env: `SIMPRA_PROJECT_URL`, `SIMPRA_DEBUG`.

A `timezone` PHP does not know stops the boot with a config error naming the key. The framework's log cannot record it, because the log needs the timezone to stamp its entries, so it goes to PHP's own error log, with `log_errors` on, and the visitor gets a blank 500: `Kernel::boot()` turns `display_errors` off before it reads the configuration, so the error is never shown.

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
    'cache_limiter' => 'nocache', // safe default for session-backed responses
],
```

`cache_limiter` controls the automatic cache headers PHP sends when a session starts. Use `nocache`
(the default), `private`, `private_no_expire`, or `''`. An empty value disables PHP's automatic
headers and is safe only when the application sets an appropriate `Cache-Control` header on every
session-backed response. `public` is rejected because it could expose per-user responses through a
shared cache.

## Log Settings

Defined in `config/log.php`:

```
'log' => [
    'level'          => 'warning', // debug | info | warning | error - any other value is rejected at boot
    'rotate_daily'   => true,      // create a new log file each day
    'retention_days' => 14,        // delete log files older than N days
    'redact_keys'    => [],        // context keys redacted besides the built-in ones
    'redact_secrets' => true,      // mask secret-bearing config DTO fields in dumps/json
],
```

Log files are written to `logs/`, one line per call. Messages and context values carry request input, so a line cannot be forged or disguised: C0 and C1 control characters, DEL, U+2028/U+2029, the bidirectional controls and U+FEFF are written escaped (`\n`, `\r`, `\t`, `\0`, otherwise `\u` and the code point, which is also a valid JSON escape, so the context stays JSON that decodes to what was logged). Invalid UTF-8 becomes `?` in the message and U+FFFD (the replacement character `�`) in a context key or value, where the JSON encoder substitutes it. The message and every context string are cut at 64 KiB, on a character boundary, ending in `...[truncated N bytes]`. The context is written at most 32 levels deep, itself the first: an array or object that would be the 33rd level is written as `"[DEPTH]"`. It holds at most 5,000 values in all, nested ones included, and past that the rest of each array is cut, ending in `"[TRUNCATED]"`. An array's own entries are counted before anything nested in them, so a key logged after a large list - a request id after 10,000 ids - is still written, and the list is what is cut. An array holding a reference to itself is therefore written down to that depth instead of exhausting memory, and logging a context never changes the caller's array, a value held by reference included. A log call never throws: a line the log file refuses (a full disk, a permission change) is written to PHP's `error_log` instead, prefixed `Logger: cannot write <file>:`.

Context keys and retained string values share a 256 KiB byte allowance before JSON escaping,
in addition to the depth and value limits. A value or the rest of a container that cannot fit
is replaced with `"[TRUNCATED]"`; JSON escaping and placeholders can make the encoded output
larger than that allowance. Objects are walked incrementally, never JSON-encoded as a whole
before applying these limits. Plain objects expose public stored properties only; backed
property getters are respected, and sensitive getters are not invoked. Purely virtual
properties need an explicit `JsonSerializable` representation to appear. Dates keep their native
fields; custom public stored properties, including those on subclasses, are walked under the
same limits and redaction rules. Enums keep their native JSON representation. A serializer or
getter that throws is logged by its class name; application code inside these callbacks is
not resource-limited by the logger.

Context values whose key names a secret are replaced with `[REDACTED]`, at any depth, in arrays and in logged objects alike. Keys compare lowercased with surrounding whitespace and every `_`, `-`, space and `.` ignored, so `accessToken`, `access_token` and `Access-Token` are one key. A key is redacted when it:

- is the built-in key `crypt_key`;
- ends in `password`, `passwords`, `passwd`, `pwd`, `authpw`, `passphrase`, `secret`, `secrets`, `token`, `tokens`, `credentials`, `authorization`, `cookie`, `cookies`, `apikey`, `privatekey`, `privatekeypem`, `accesskey`, `secretkey`, `signingkey`, `hmackey`, `masterkey` or `encryptionkey` (`db_password`, `id_token`, `refresh_tokens`, `x-api-key`, `jwt_signing_key`);
- or matches an entry of `redact_keys`, compared the same way but without the suffix rule.

The headers are suffixes so that `$_SERVER`'s names match as well as the header's own: `Authorization`, `Proxy-Authorization`, `HTTP_AUTHORIZATION` and `REDIRECT_HTTP_AUTHORIZATION`; `Cookie`, `Set-Cookie` and `HTTP_COOKIE`; `PHP_AUTH_PW`. A plural does not end in its singular, so the plurals are listed: `$_COOKIE` logged as `cookies`, a set of `secrets`. That redacts a count named in the plural too - `max_tokens` is written as `[REDACTED]`, so log a count as `token_count`.

`redact_keys` **adds** to the built-in keys; no configuration removes one. `private_key_bits`, `token_ttl`, `cookie_consent` and `cache_key` end in none of the suffixes and stay visible. Redaction goes by key name only: a header list of name/value pairs (`[['name' => 'Authorization', 'value' => '...']]`) or raw header lines (`['Authorization: Bearer ...']`) keeps the secret under a key that names nothing, so log a header map keyed by header name, or leave the header out. Use `#[\SensitiveParameter]` on method parameters holding secrets to keep them out of traces. `redact_secrets` masks secret-bearing config DTO fields when dumped through `var_dump()` or `json_encode()`.

Configured secret **values** are also stripped wherever they turn up in free text, which key names cannot reach: an exception message carrying a DSN, a URL with a credential in its query. Every string the configuration holds under a key the rules above name - from any layer, the environment included - is replaced with `[REDACTED]` in the message and in every context string, the stack trace the error handler logs among them. That covers `database.password` (or `SIMPRA_DB_PASS`), `extensions.mail.smtp.password`, each OIDC `client_secret`, a `crypt_key` a project configures, a value under a `redact_keys` name, and every string of a list or map kept under such a key (`api_tokens => [...]`). Each value is matched as written, URL-encoded, and escaped as inside a JSON string, and before the 64 KiB cut, so a secret straddling it leaves no prefix behind. A value shorter than 8 bytes is not stripped: it would match ordinary text and corrupt unrelated lines, so a real secret should be longer. The rule goes by the key, never by what the value is: a project key that ends in one of the suffixes or is listed in `redact_keys` but holds something public of 8 bytes or more - a `session_cookie` holding the cookie's name, a `csrf_token` holding a form field name, an endpoint map's `token` or `authorization` URL - has that value replaced with `[REDACTED]` wherever it appears in every log line, URLs and messages that merely mention it included. Name such keys for what they hold (`session_cookie_name`, `token_url`, `authorization_endpoint`), none of which ends in a suffix. The values are collected once per request, when the first line is written. Only values the configuration holds are known - a secret read from a vault, a database row or a request is not, so keep it out of messages yourself. Context keys are stripped like context strings: a key that is or contains a secret is written with `[REDACTED]` in its place, and a key that then has the same name as one already written in that array gets `#2` appended (`#3` and on, in the order logged), so neither value is lost. The `error-log` extension strips the same values from the message and trace it stores, and the error handler from what it reports to PHP's `error_log` when writing the log itself fails.

Secret collection itself is limited to 32 map levels, 5,000 entries and 256 KiB of key names
and unique secret values. Shared secret strings are collected only once. If the scan exceeds
these limits or fails, the writer omits the entire caller message and context, writing only
`[REDACTED: configured secrets could not be collected safely]` for each line for the rest of
that writer's lifetime. A partial scan is never treated as complete redaction. Simplify a
recursive or oversized application config to restore ordinary log payloads.

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

MySQL connection sessions are always pinned to `+00:00`, so database defaults such as
`CURRENT_TIMESTAMP(6)` store UTC. This is deliberately not configurable. `project.timezone`
controls user-facing display and input, not storage.

The framework reserves the MySQL init-command PDO option to establish UTC before the first
statement. Do not set `PDO::MYSQL_ATTR_INIT_COMMAND` or `Pdo\\Mysql::ATTR_INIT_COMMAND`; a
connection carrying an application value for it is refused rather than silently overwritten.
Execute any additional session setup explicitly after connecting.

`database.options` is currently limited to string keys, so PDO attribute constants (which are
integers) set there never reach the driver. Do not rely on it for connection options such as TLS
settings, or for timeouts: MySQL waits are bounded by `database.connect_timeout` (1-300 seconds,
default 10) and `database.read_timeout` (1-86400 seconds, default 300), which also caps a
`Db::lock()` wait. See [Database: Timeouts](07-Database.md).

Use `database.ssl` for MySQL TLS, for example in `config/framework.local.php`:

```php
'database' => [
    // Other database settings stay unchanged.
    'ssl' => [
        'ca' => '/etc/ssl/certs/mysql-ca.pem',
        'cert' => '',
        'key' => '',
        'verify_server_cert' => true,
    ],
],
```

A CA or client certificate/key pair requests TLS. Client certificates are optional,
but `cert` and `key` must be supplied together. Keep private keys outside `public/`
and readable only by the application account. Server certificate verification defaults
to true; configure the issuing CA rather than disabling verification. An empty SSL
section requests no TLS. These settings apply to MySQL, not other PDO drivers.

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

All `SIMPRA_*` variables map to config paths and are merged in as the highest-precedence layer (same list-replace semantics as above). Empty or missing variables are silently skipped.

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

The framework creates the bundle directory recursively on first request when the parent is writable. In production, pre-create it when you need strict owner/mode control, especially for tmpfs paths such as `/run/simpra`. No other account may write to it: a bundle is PHP the framework executes, so a file placed there by another account runs as the web server. Never use a shared directory such as `/tmp`.

After changing any `config/*.php` default (not the local file or env vars), delete the bundle cache so it is rebuilt:

```
rm cache/config.php cache/core.php cache/extensions.php
```
