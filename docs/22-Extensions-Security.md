# Security Extension

## Configuration

Create `config/security.php` in your project. Each header is a plain string value under the `headers` key.

```php
return [
    'extensions' => [
        'security' => [
            'enabled' => true,
            'headers' => [
                'content-security-policy'   => "default-src 'self'; script-src 'self' 'nonce-{_csp_nonce}'; ...",
                'x-content-type-options'    => 'nosniff',
                'x-frame-options'           => 'DENY',
                'referrer-policy'           => 'strict-origin-when-cross-origin',
                'strict-transport-security' => 'max-age=31536000; includeSubDomains',
                'permissions-policy'        => 'camera=(), microphone=(), geolocation=()',
                'cross-origin-opener-policy' => 'same-origin',
            ],
        ],
    ],
];
```

`Content-Security-Policy` (and its report-only form), `X-Frame-Options`, `Permissions-Policy` and the cross-origin opener and embedder policies go on HTML pages and on XML documents - `text/xml`, `application/xml` and every `+xml` type, SVG, XHTML, RSS and Atom among them - which run script when opened directly. Other responses, JSON among them, get the remaining headers. Only an HTML page has `{_csp_nonce}` filled in its body, so an XML document gets the CSP with its nonce source removed: a nonce its body cannot carry would block its scripts and make every response different.

Use `{_csp_nonce}` in CSP header values and matching script tags to allow trusted inline scripts without enabling `'unsafe-inline'`. The security extension replaces it with a fresh random nonce for each HTML response, anywhere in the page. So it must never reach a page in text the application did not write: `tokens()` and `Format::escape()` encode its braces, but `rawTokens()` inserts HTML as it is, and a nonce handed to injected markup no longer contains it.

Error pages are the one exception: they are rendered outside the extension pipeline, so nothing substitutes the placeholder. Rather than publish a fixed, source-visible nonce, the error handler drops the whole `'nonce-...'` expression from the header and keeps the rest of the policy, which leaves error pages under a stricter CSP than normal responses. Inline scripts must therefore never be relied on in error templates.

```
<script nonce="{_csp_nonce}">
    window.appReady = true
</script>
```

## Caching

A public `Cache-Control` in this list lets a CDN cache the site's pages, and so does a header a cache reads ahead of it: `CDN-Cache-Control`, `Cloudflare-CDN-Cache-Control`, `Surrogate-Control`, Akamai's `Edge-Control` or nginx's `X-Accel-Expires`. A shared cache stores a response under its URL and serves it to everyone, so these headers, and `Expires`, reach only a response that is the same for every visitor.

A response bound to one visitor or one request gets `Cache-Control: no-store` instead, and none of these headers - neither the configured ones nor any it set itself, since its controller cannot know that a later hook starts a session. The same holds on the error page. Only a policy a response set on the `Response` that already keeps every shared cache out stands: `no-store`, or `private` naming no fields, in its `Cache-Control`, with none of the headers a cache reads ahead of it. `private="Set-Cookie"` does not count, since a shared cache may keep the rest of the response. With no cache policy configured, the extension leaves a header sent with `header()` - the ones `session.cache_limiter` makes PHP send among them - as it is; the error handler still sends `no-store` on a bound error page. A response is bound when:

- it is an error that depends on the request or on who sent it, or that is transient: every 4xx except 404 and 410 (a 400, 401, 403 or 429, for instance) and every 5xx. The error handler sends `no-store` on those pages even when nothing is configured;
- the request used a session, including after `session_write_close()` releases the session lock or `Session::destroy()` ends it. The session starts only when something uses it - a CSRF field, a flash message, a signed-in check with a session cookie - so a page that never does stays cacheable;
- the request carried credentials (`Authorization`, Apache's `REDIRECT_HTTP_AUTHORIZATION` copy of it after a front-controller rewrite, PHP's parsed Basic or Digest credentials, or a server-authenticated `REMOTE_USER` or `REDIRECT_REMOTE_USER`), which a shared cache would otherwise not reuse a response for. An empty value does not count: some server configurations pass these on empty for every request without credentials. A CDN or load balancer that authenticates itself to the origin with `Authorization` makes every response count, so no page is cached: send origin authentication in a header of its own instead;
- it sets a cookie, through `Response::cookie()` - the way to set one - a `Set-Cookie` header on the `Response`, or `setcookie()`;
- it carries a CSP nonce, in the page or in a header: the nonce is fresh for each response, and a cached one would be the same for every visitor until the cache expires. A site that wants a CDN to cache its pages uses a CSP without `{_csp_nonce}`.

Any other response that sets one of these headers itself, on the `Response` or with `header()`, keeps its own policy, and none of the configured ones is added beside it. A header sent with `header()` before an exception counts on the error page too. The redirects of `auth` and `oidc-login` - a guest sent to sign in, a signed-in visitor denied, a sign-in started or completed, a logout - send `no-store` themselves. A guest is redirected without a session, and a cached copy under a protected URL would keep signed-in visitors out of it.

A 404 or 410 keeps the configured value, since it is the same for every visitor; its staleness after a page is published is bounded by the `s-maxage` you set. `Response::isUncacheableError($status)`, `Response::isVisitorBound()` and `Response::SHARED_CACHE_HEADERS` hold the rule. The extension runs after the other bundled hooks (`PRIORITY = 100`), so it sees the final status and a session any of them started; an extension of your own that starts a session in `after()` needs a lower priority.

Every other response gets the configured value, so set a public one only on a site whose pages are the same for every visitor. A page that shows a guest something different from a signed-in visitor without starting a session is cached as the guest saw it and served to both. A CDN rule set to ignore the origin's headers bypasses all of this.

## Public API

The security extension is purely a Boot hook - there is no public facade to call from controllers. It implements `Hook` and injects its headers in the `after()` phase, after the controller and layout have produced the final response.

To add a header from a controller directly, use the response object:

```
Response::html($body)->header('X-Custom-Header', 'value');
```

The security extension runs in `after()`. If it configures the same header name as your controller response, the extension value is the final value - except a cache header, where the response's own policy stands unless the response is bound to one visitor and the policy lets a shared cache keep it (see [Caching](#caching)). Put route-specific exceptions in config or set the configured value to an empty string to suppress that header globally.

## Example

A realistic CSP for a project with external fonts and a CDN for images:

```
'headers' => [
    'content-security-policy' =>
        "default-src 'self'; " .
        "script-src 'self' 'nonce-{_csp_nonce}'; " .
        "style-src 'self' https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' https://cdn.example.com data:; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "object-src 'none'",
    'x-content-type-options'    => 'nosniff',
    'x-frame-options'           => 'DENY',
    'referrer-policy'           => 'strict-origin-when-cross-origin',
    'strict-transport-security' => 'max-age=63072000; includeSubDomains',
]
```

Start with a restrictive policy and loosen individual directives as needed. Use `Content-Security-Policy-Report-Only` during development to catch violations without blocking anything.
