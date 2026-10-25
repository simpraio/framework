# SEO

## Configuration

Create `config/seo.php` in your project. The `defaults` section provides fallback values when no database row exists for the current route.

```php
return [
    'extensions' => [
        'seo' => [
            'enabled'   => true,
            'cache_ttl' => 3600,
            'defaults'  => [
                'title'       => 'My Site',
                'description' => 'The default meta description.',
            ],
        ],
    ],
];
```

The `seo` database table is required for DB-backed page metadata. Schema is in `tools/schema/seo.sql`. Each row maps a `path_id` and `language` to a `title`, `description`, and optional `canonical_url`.

## Public API

The seo extension is a `Contributor`. It injects four tokens into the layout on every request:

```
{SITE_NAME}        // project name from config/app.php
{HEAD_TITLE}       // title from seo table, or config default
{HEAD_DESCRIPTION} // description from seo table, or config default
{CANONICAL_URL}    // canonical_url from seo table, or auto-computed from the current URL
```

Place them in the `<head>` section of your layout template:

```
<title>{HEAD_TITLE}</title>
<meta name="description" content="{HEAD_DESCRIPTION}">
<link rel="canonical" href="{CANONICAL_URL}">
```

| Call | Returns | Description |
| --- | --- | --- |
| `Store::page($route)` | `array` | Fetches the seo row for a route from the database (or APCu cache). Returns an array with `title`, `description`, and `canonical_url` keys. Empty strings indicate no database row. |

## Schema

Run `tools/schema/seo.sql` against your database before enabling DB-backed page metadata.

```
CREATE TABLE `seo` (
    `path_id`       VARCHAR(64)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `language`      CHAR(2)       CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `title`         VARCHAR(255)  NOT NULL DEFAULT '',
    `description`   TEXT          NOT NULL,
    `canonical_url` VARCHAR(512)  NOT NULL DEFAULT '',
    PRIMARY KEY (`path_id`, `language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`path_id` is the route identifier in `module/controller` format (e.g. `main/info` for the homepage). `language` is a two-letter code matching the language configured in `config/app.php`. Leave `canonical_url` empty to have the extension compute it automatically from the current request URL.

## Example

Database rows that drive the extension - one row per route per language:

```
INSERT INTO `seo` (`path_id`, `language`, `title`, `description`, `canonical_url`) VALUES
('main/info',    'en', 'Simpra - PHP Framework', 'No magic. No overhead.', 'https://simpra.io/'),
('docs/start',   'en', 'Getting Started - Simpra', 'Setup in minutes.', ''),
('docs/routing', 'en', 'Routing - Simpra', 'URL-to-controller convention.', '');
```

The `path_id` is the route's canonical identifier - `module/controller`, e.g. `main/info` for the homepage. For routes with an `id` segment, use `module/controller` without the id (the extension matches at the path level, not per-item).

When `canonical_url` is empty, the extension computes it from the current request URL and the `url` in `config/app.php`. Set that URL in production so generated canonicals are absolute.
