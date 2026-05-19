# Translation

## Configuration

Create `config/translation.php` in your project. The shipped config keeps this extension disabled; enable it when your project stores translations in the database.

```php
return [
    'extensions' => [
        'translation' => [
            'enabled'   => true,
            'cache_ttl' => 3600,
        ],
    ],
];
```

The Boot class implements `Contributor`. On every request it loads two sets of translation strings from the database: layout-wide tokens (where `path_id = 'layout'`) and page-specific tokens (where `path_id` matches the current route). Both are injected as `rawTokens` - page tokens override layout tokens with the same key.

## Public API

Use these methods when you need translations in PHP code (controllers, services) rather than in templates:

| Call | Returns | Description |
| --- | --- | --- |
| `Translation::text($pathId, $id, $language, $values)` | `string` | Returns the translation string HTML-escaped. Use for plain-text output in PHP code. `$values` is an optional substitution map applied before escaping. |
| `Translation::html($pathId, $id, $language, $values)` | `string` | Returns the translation string with `$values` HTML-escaped but the surrounding text unescaped - for translations that contain HTML markup. |
| `Translation::clear($pathId, $language)` | `void` | Clears the APCu cache for a given path and language. Omit `$language` to clear all languages for that path. |

In templates, tokens are referenced directly without calling PHP - the contributor injects them automatically:

```
<!-- Template - token names are the uppercased `id` column value -->
<h1>{HERO_HEADLINE}</h1>
<p>{HERO_SUBLINE}</p>
```

## Schema

Run `tools/schema/translation.sql` against your database before enabling DB-backed translations.

```
CREATE TABLE `translation`
(
    `path_id`  VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `language` CHAR(2)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `id`       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `text`     TEXT                                              NOT NULL,
    PRIMARY KEY (`path_id`, `language`, `id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```

`path_id` is the route identifier (`module/controller`) or the special value `layout` for tokens shared across all pages. `id` is the translation key - stored in any case but matched case-insensitively (the contributor uppercases it before injecting). The token name in the template is the uppercased `id`.

## Example

Insert translation rows and use the tokens in a template:

```
-- Layout-wide tokens (available on every page)
INSERT INTO `translation` (`path_id`, `language`, `id`, `text`) VALUES
('layout', 'en', 'NAV_HOME',    'Home'),
('layout', 'en', 'NAV_DOCS',    'Documentation'),
('layout', 'en', 'FOOTER_COPY', '&copy; 2026 Acme');

-- Page-specific tokens (only on main/info)
INSERT INTO `translation` (`path_id`, `language`, `id`, `text`) VALUES
('main/info', 'en', 'HERO_HEADLINE', 'Build something <strong>fast</strong>'),
('main/info', 'en', 'HERO_SUBLINE',  'No magic. No overhead.');
```

```
<!-- In the layout template -->
<nav>
    <a href="/">{NAV_HOME}</a>
    <a href="/docs/start">{NAV_DOCS}</a>
</nav>

<!-- In templates/main/info.html -->
<h1>{HERO_HEADLINE}</h1>
<p>{HERO_SUBLINE}</p>
```

After editing rows in the database, call `Translation::clear('main/info', 'en')` or restart the server to expire the APCu cache.
