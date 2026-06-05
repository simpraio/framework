# Error Log

## Configuration

Create `config/error-log.php` in your project. The example below enables DB-backed error logging and shows the supported keys.

```php
return [
    'extensions' => [
        'error-log' => [
            'enabled'        => true,
            'retention_days' => 30,          // auto-delete entries older than N days
            'store_trace'    => true,          // persist full stack traces
            'redact_keys'    => [],            // query-param names to redact in stored URLs
        ],
    ],
];
```

The `error_log` database table is required for DB-backed error logging. Schema is in `tools/schema/error-log.sql`.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Logger::log($throwable, $config)` | `void` | Writes the exception to the `error_log` table. The `$config` argument is optional - omitting it reads from the extension config automatically. Internal sink failures fall back to `error_log()` to avoid masking the original exception. |
| `Logger::purge($retentionDays)` | `void` | Deletes rows older than `$retentionDays`. Called automatically by the Boot hook once per day. |

The Boot class implements `Bootable` and `Hook`. On boot, it registers a global error handler that calls `Logger::log()` for every unhandled exception. The `before()` hook triggers a daily purge via `Cache::once()`.

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

`exception` stores the fully-qualified class name of the thrown exception. `trace` is empty when `store_trace` is `false`. `url` has sensitive query parameters scrubbed according to `redact_keys`. The index on `created_at` supports the daily purge query.

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

With URL redaction - the `token` parameter value is never stored in the database:

```
use extensions\error_log\Config;
use extensions\error_log\Logger;

$config = Config::fromArray([
    'store_trace' => false,
    'redact_keys' => ['token', 'secret'],
]);

Logger::log($e, $config);
```
