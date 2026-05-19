# Routing

## Convention Routing

There is no route table. The URL maps directly to a module and controller by convention:

```
/{module}/{controller}/{id - }
```

The router strips trailing slashes, normalizes segments to lowercase, and resolves based on segment count:

```
/                     -> defaultModule / defaultController   (config/app.php)
/docs                 -> defaultModule / docs
/docs/start           -> docs / start
/blog/post/42         -> blog / post,  id = "42"
/a/b/c/d              -> 404  (more than 3 segments after language)
```

The default module and controller are set in `config/app.php` under `route.default_module` and `route.default_controller`. Both default to `main` and `info` respectively.

## Segment Rules

Every URL segment is validated before dispatch. Allowed characters are lowercase letters, digits, and hyphens - nothing else. Uppercase is lowercased automatically. Any other character (dot, underscore, slash, space, `%`, `..`) causes an immediate `404` before any file is touched.

```
/blog/my-post         [ok]  valid
/blog/My-Post         [ok]  normalized to /blog/my-post
/blog/my_post         [x]  underscore not allowed -> 404
/blog/../config       [x]  dot not allowed -> 404
/blog/post%20name     [x]  percent-encoded characters -> 404
```

This whitelist is the primary path-traversal guard. Paths reaching the filesystem (controller class loader, template loader) are constructed from pre-validated segments only.

## Controller Naming

The controller segment is converted to a PascalCase class name: hyphens act as word separators, each word is capitalized, then hyphens are removed.

```
URL segment      Class (in modules/{module}/)
---------------------------------------------
post             Post
my-post          MyPost
about-us         AboutUs
```

The template path uses the original hyphenated segment name: `templates/{module}/{controller}.html`. No mapping is applied to the template path.

```
/blog/my-post  ->  modules\blog\MyPost::compose()
               ->  templates/blog/my-post.html
```

## Template-Only Routes

A controller class is not required. If a matching template exists but no controller class does, the template is rendered directly without calling `compose()`. The layout still wraps it.

```
# no modules/docs/Start.php needed - template alone is enough
templates/docs/start.html  ->  renders at /docs/start
```

If a controller exists but returns a `Template` and no matching template file is found, the request returns `404`. Controllers that always return a `Response` (redirects, JSON, file downloads) do not need a template.

## Language Prefix

When `language.available` is a non-empty list in `config/app.php`, the router checks whether the first URL segment matches one of the available language codes. If it does, that segment is consumed as the language and excluded from module/controller resolution.

```
# language.available = ['en', 'cs', 'de']

/en/blog/post/42    -> language=en, module=blog, controller=post, id=42
/cs/blog/post/42    -> language=cs, module=blog, controller=post, id=42
/blog/post/42       -> language=en (default), module=blog, controller=post, id=42
```

The resolved language is available inside a controller as `$this->route->language`. When `language.available` is empty, no language prefix is consumed and every request uses the configured default language.

## Public URL Aliases

Aliases let you map arbitrary public URLs to internal module/controller pairs. The alias table is checked before convention routing, so an alias overrides the convention path entirely.

Enable the alias system in `config/app.php`:

```
// config/app.php
'route' => [
    'aliases' => [
        'enabled'   => true,
        'cache_ttl' => 3600,  // seconds
    ],
],
```

Aliases are stored in the `aliases` database table and require a database connection. Apply the schema before enabling:

```
tools/schema/aliases.sql
```

```
# example rows
language  path                    module   controller
en        company/about-us        company  about
cs        spolecnost/o-nas        company  about
de        unternehmen/ueber-uns   company  about
```

The path column uses the same character rules as URL segments: lowercase letters, digits, and hyphens only - with `/` as the segment separator. The public alias URL is joined with the language prefix: `/{language}/{path}`.

For reverse lookup - generating a canonical public URL from a route object - use the static helper:

```
Aliases::uri($route);   // returns '/cs/spolecnost/o-nas' or null if no alias
```

## Security Notes

- **Segment whitelist** - the character whitelist (`a-z 0-9 -`) blocks path traversal, null bytes, encoded slashes, and all other unsafe inputs before any filesystem path is constructed. No sanitization step is needed downstream.
- **Depth limit** - more than 3 segments (after an optional language prefix) returns `404`. This caps the attack surface for path-based probing.
- **Host header validation** - when `allowed_hosts` is set in `config/app.php`, the framework returns `400` for requests with a Host header not in the list. Set this in production to prevent host header injection. An empty list disables the check (development default).
- **Template paths** - the view loader validates template names independently. Dots are rejected, making directory traversal sequences like `../` impossible. Underscores are allowed (used for internal templates like `_base` that are never reachable via URL). A controller cannot load a template outside `templates/`.
