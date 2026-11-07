# Error Log

## Configuration

Create `config/error-log.php` in your project. The example below enables DB-backed error logging and shows the supported keys.

```php
return [
    'extensions' => [
        'error-log' => [
            'enabled'        => true,
            'retention_days' => 30,          // auto-delete entries older than N days; 0 keeps every entry
            'store_trace'    => true,          // persist full stack traces
            'redact_keys'    => [],            // query-param names to redact in stored URLs, besides log.redact_keys and the built-in ones
        ],
    ],
];
```

The `error_log` database table is required for DB-backed error logging. Schema is in `tools/schema/error-log.sql`.
Its `created_at` value comes from the database's UTC `CURRENT_TIMESTAMP(6)` default, so inserts
and `NOW()`-based retention use the same clock. Convert to a display timezone only when presenting it.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Logger::log($throwable, $config)` | `void` | Writes the exception to the `error_log` table. The `$config` argument is optional - omitting it reads from the extension config automatically. Internal sink failures fall back to `error_log()` to avoid masking the original exception. |
| `Logger::purge($retentionDays)` | `void` | Deletes rows older than `$retentionDays`. A value of `0` or less deletes nothing, so `retention_days = 0` keeps every row and leaves cleanup to you; a negative `retention_days` is read as `0`. Called automatically by the Boot hook once per day. |

The Boot class implements `Bootable` and `Hook`. On boot, it attaches `Logger::log()` as an additional sink to the core error handler. The sink covers unhandled exceptions after extension initialisation; primary file logging remains active independently. The `before()` hook triggers a daily purge via `Cache::once()`.

## Schema

Run `tools/schema/error-log.sql` against your database before enabling the extension.

```
CREATE TABLE `error_log` (
    `id`         INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME(6)        NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `exception`  VARCHAR(191)       CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `message`    TEXT               NOT NULL,
    `file`       VARCHAR(512)       NOT NULL,
    `line`       MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `trace`      MEDIUMTEXT         NOT NULL,
    `url`        VARCHAR(2048)      NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_exception` (`exception`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`exception` stores the fully-qualified class name of the thrown exception. `trace` is empty when `store_trace` is `false`. `message` and `trace` have the value of every configured secret replaced with `[REDACTED]`, the same values the log file strips (see [Log Settings](02-Configuration.md#log-settings)); an exception logged before the configuration is loaded is stored as thrown, and when the secret scan stopped at its limits both hold `[REDACTED: configured secrets could not be collected safely]` instead, as the log file does. `url` has sensitive query parameters scrubbed by the same rule as log context (see `log.redact_keys` in [Configuration](02-Configuration.md#log-settings)): the built-in secret names and suffixes, every name in `log.redact_keys` and in this extension's `redact_keys`, ignoring case and `_`, `-`, space and `.`, whether written literally or in a form PHP reads as that name (`token%00x`, `token[a]`, ` token`). A name treated as secret in log context is therefore not stored in a query string either, and `?password=`, `?reset_token=` or `?api_key=` are scrubbed with no configuration at all. The index on `created_at` supports the daily purge query.

## Example

Log a caught exception manually - useful for errors you handle gracefully but still want a record of:

```
use extensions\error_log\Logger;

try {
    ExternalApi::call();
} catch (Throwable $e) {
    Logger::log($e);
    return Response::redirect('/error/');
}
```

With URL redaction of names that are not built in - the `signature` and `nonce` parameter values are never stored in the database:

```
use extensions\error_log\Config;
use extensions\error_log\Logger;

$config = Config::fromArray([
    'enabled' => true,
    'store_trace' => false,
    'redact_keys' => ['signature', 'nonce'],
]);

Logger::log($e, $config);
```
