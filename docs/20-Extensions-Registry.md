# Registry

## Configuration

Create `config/registry.php` in your project. The example below shows the shipped disabled config and cache setting.

```php
return [
    'extensions' => [
        'registry' => [
            'enabled'   => false,
            'cache_ttl' => 60,   // seconds to cache values in APCu
        ],
    ],
];
```

The `registry` database table is required for DB-backed registry values. Schema is in `tools/schema/registry.sql`. Rows have a `group`, `key`, `language`, and `value` column. A `language` of `''` (empty string) marks language-agnostic system settings.

## Public API

Two facades are available depending on the type of data:

| Call | Returns | Description |
| --- | --- | --- |
| `Registry::get($group, $key, $language)` | `?string` | Returns a single value from the registry for the given group, key, and language. `$language` defaults to the current request language. |
| `Registry::group($group, $language)` | `array` | Returns all key-value pairs for a group and language as an associative array. |
| `Settings::get($key, $default)` | `?string` | Returns a single value from the reserved `system` group (language-agnostic). Use for global settings like site name or feature flags. |
| `Settings::all()` | `array` | Returns all key-value pairs in the system group. |

## Schema

Run `tools/schema/registry.sql` against your database before enabling DB-backed registry values.

```
CREATE TABLE `registry` (
    `group`    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `key`      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `language` VARCHAR(2)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    `value`    TEXT        NOT NULL,
    PRIMARY KEY (`group`, `key`, `language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`group` is a logical namespace (e.g. `homepage`, `footer`). `key` is the value name within that group. `language` is a two-letter code (`en`, `de`, etc.) - set it to `''` (empty string) for language-agnostic entries, which are the only ones accessible via `Settings`. The reserved group `system` is used exclusively by the `Settings` facade.

## Example

Read CMS-editable content from a controller and pass it to a template:

```
use extensions\registry\Registry;
use extensions\registry\Settings;

public function compose(Template $template): Template|Response
{
    $hero = Registry::group('homepage'); // ['headline' => '...', 'subline' => '...']
    $maintenance = Settings::get('maintenance_mode', '0');

    if ($maintenance === '1') {
        return Response::html('Under maintenance', 503);
    }

    return $template->tokens([
        'HERO_HEADLINE' => $hero['headline'] ?? '',
        'HERO_SUBLINE'  => $hero['subline'] ?? '',
    ]);
}
```
