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

Use `{_csp_nonce}` in CSP header values and matching script tags to allow trusted inline scripts without enabling `'unsafe-inline'`. The security extension replaces it with a fresh random nonce for each HTML response.

```
<script nonce="{_csp_nonce}">
    window.appReady = true
</script>
```

## Public API

The security extension is purely a Boot hook - there is no public facade to call from controllers. It implements `Hook` and injects its headers in the `after()` phase, after the controller and layout have produced the final response.

To add a header from a controller directly, use the response object:

```
Response::html($body)->header('X-Custom-Header', 'value');
```

The security extension runs in `after()`. If it configures the same header name as your controller response, the extension value is the final value. Put route-specific exceptions in config or set the configured value to an empty string to suppress that header globally.

## Example

A realistic CSP for a project with external fonts and a CDN for images:

```
'headers' => [
    'content-security-policy' =>
        "default-src 'self'; " .
        "script-src 'self' 'nonce-{_csp_nonce}'; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
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
