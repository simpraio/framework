# Security

## Built-in Protections

Several security controls are active on every request without any configuration:

- **Segment whitelist** - URL segments allow only `a-z 0-9 -`. Dots, underscores, encoded characters, and traversal sequences are rejected with `404` before any filesystem path is constructed.
- **Depth limit** - more than 3 URL segments (after an optional language prefix) returns `404`.
- **Token escaping** - `tokens()` runs every value through `htmlspecialchars` and escapes `{` and `}`, preventing XSS and template injection from user-supplied data.
- **Redirect guard** - `Response::redirect()` only accepts paths starting with `/`. External URLs, protocol-relative URLs, and CR/LF characters throw `InvalidArgumentException`.
- **Header injection guard** - header names and values containing CR, LF, or NUL throw `InvalidArgumentException` before any header is set.
- **Error concealment** - in production (`debug: false`), 5xx error pages show a generic message. Stack traces and file paths are only exposed when `debug: true`.

## CSRF Protection

The `csrf` extension validates unsafe requests (POST, PUT, PATCH, DELETE). It is **disabled by default**; enable it before accepting form submissions from users.

The extension does not add tokens to forms automatically. Add the hidden field to every unsafe form yourself:

```
<form method="post">
    <input type="hidden" name="_csrf" value="{_csrf}">
    <button type="submit">Save</button>
</form>
```

See [Extension: csrf](12-Extensions-CSRF.md) for configuration, AJAX headers, token lifecycle, and response behavior.

## Security Headers

The `security` extension adds HTTP security headers in the response `after()` hook and is **enabled by default**.

See [Extension: security](21-Extensions-Security.md) for exact defaults, config syntax, HSTS behavior, and HTML-only response rules.

## Rate Limiting

The `ratelimit` extension enforces a per-IP request cap. It is **disabled by default** - enable it and set limits in `config/ratelimit.php`:

```
'extensions' => [
    'ratelimit' => [
        'enabled' => true,
        'max'     => 60,   // requests allowed per window
        'window'  => 60,   // window size in seconds
    ],
],
```

Exceeding the limit returns `429 Too Many Requests`. The counter is per-IP, fixed-window, stored in APCu - **APCu must be enabled** for this extension to function.

## Session Security

Session defaults are secure out of the box. The defaults live in `config/session.php`:

```
'session' => [
    'name'      => 'SID',
    'lifetime'  => 0,        // browser session - deleted on close
    'secure'    => true,     // HTTPS only
    'http_only' => true,     // no JavaScript access
    'same_site' => 'Lax',    // 'Strict', 'Lax', or 'None'
],
```

Additionally, the session layer sets `session.use_strict_mode = 1` (rejects uninitialized session IDs) and `session.use_only_cookies = 1` (session ID never in the URL).

## Error Exposure

The error handler behaviour differs between debug and production mode, controlled by `debug` in `config/app.php`:

```
// debug: true  - development
500 -> exception class, message, file, line number, full stack trace

// debug: false - production
500 -> "Something went wrong. Please try again later." - no details
```

4xx errors always show their standard message regardless of mode (e.g. `404 Not Found` is not a security-sensitive disclosure). 5xx details are suppressed in production.

`display_errors` is disabled by the error handler in all modes - errors are never printed to the response by PHP itself. In production, all exceptions are logged to the application log (and to the `error_log` database table if the `error-log` extension is enabled).

## Host Header Validation

Set `allowed_hosts` in `config/app.php` to prevent host header injection and cache poisoning. Requests with a `Host` header not in the list receive `400 Bad Request` before any routing occurs.

```
'project' => [
    'allowed_hosts' => ['example.com', 'www.example.com'],
],
```

An empty list (the default) disables the check - acceptable for development, not for production. The comparison is case-insensitive and uses strict equality.

## Outbound Requests (SSRF / Egress)

The `http-client` extension enforces an egress allowlist (`extensions.http-client.egress`): when enabled, requests may only target allowlisted hosts, and with `block_private_ips` on, an allowlisted host that resolves to a private or reserved IP is rejected as defense in depth. Redirect targets are checked against the same policy before they are followed. The outbound proxy is operator-config only and is never accepted as a per-request option (a request-supplied proxy would bypass the allowlist). See [Extension: HTTP Client](16-Extensions-Httpclient.md).

The client follows up to five HTTP redirects manually instead of using cURL's automatic redirect handling, so each `Location` target is rechecked before the next request is made. When a redirect changes origin, sensitive request state (`Authorization`, `Proxy-Authorization`, `Cookie`, and explicit cookie-jar use) is stripped before the next hop.

## Production Checklist

- Set `debug: false` - hides stack traces and internal paths from error responses.
- Set `allowed_hosts` - prevents host header injection; leave empty only during development.
- Serve only the `public/` directory from the web root - the project root must never be publicly accessible.
- Keep secrets (database passwords, API keys) out of committed config files - use environment variables or a local config override.
- Set `session.secure: true` - session cookies are HTTPS-only by default; do not override this in production.
- Enable the `csrf` extension and add `{_csrf}` to every unsafe form.
- Enable the `security` extension (on by default) and review the CSP policy for your specific assets and third-party origins.
- Enable APCu when using `ratelimit`. APCu is also strongly recommended for shared template, asset-version, route-alias, SEO, translation, registry, auth access, auth group, and error-log purge caches. Without APCu, general cache entries live only for the current request, so later requests recompute them and repeat any backing database or filesystem lookup. Auth login throttling still works without APCu by using locked local files, but those counters are host-local.
- Use `tokens()` for all user-facing values; reserve `rawTokens()` for HTML you produced.
- Validate and whitelist redirect destinations in your controllers before passing them to `Response::redirect()`.
- Clear the bundle cache (`rm -f cache/*.php`) on every deploy after a config or framework change - stale bundles silently serve old config and can re-enable disabled extensions (see [Deployment: Updating Code or Configuration](26-Deployment.md#updating-code-or-configuration)).
- For `http-client` egress, keep the allowlist narrow and include only the external hosts the application actually calls.
