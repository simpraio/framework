# CSRF

## Configuration

Create `config/csrf.php` in your project. The shipped config keeps this extension disabled; enable it before accepting unsafe form submissions.

```php
return [
    'extensions' => [
        'csrf' => [
            'enabled' => true,
        ],
    ],
];
```

The token is validated on every `POST`, `PUT`, `PATCH`, and `DELETE` request. Requests without a valid token receive a `419` response.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Guard::token()` | `string` | Returns the current session CSRF token, generating and storing one if it does not exist yet. |
| `Guard::valid($request)` | `bool` | Checks the token submitted via the `_csrf` POST field or the `X-CSRF-Token` header against the session token using constant-time comparison. |
| `Guard::requiresCheck($request)` | `bool` | Returns `true` for mutating methods (POST, PUT, PATCH, DELETE). |

Three constants expose the key names used by the guard:

```
Guard::FIELD        // '_csrf'         - POST field name
Guard::HEADER       // 'X-CSRF-Token'  - header name for AJAX requests
Guard::SESSION_KEY  // '_csrf_token'   - session key
```

## Example

Emit the token in an HTML form. The Boot hook validates it automatically on submission - no controller code required.

```
<!-- In your template -->
<form method="post" action="/contact/">
    <input type="hidden" name="_csrf" value="{_csrf}">
    <!-- your fields -->
</form>
```

The `{_csrf}` placeholder is replaced by the csrf `after()` hook in HTML responses. For AJAX, expose the token yourself, for example in a meta tag, and send it as a header:

```
<meta name="csrf" content="{_csrf}">
```

```
// fetch with CSRF header
fetch('/api/save/', {
    method: 'POST',
    headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf]').content },
    body: JSON.stringify(payload),
});
```
