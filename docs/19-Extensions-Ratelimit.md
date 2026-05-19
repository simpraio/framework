# Rate Limit

## Configuration

Create `config/ratelimit.php` in your project. The example below enables global per-IP throttling and shows the supported keys.

```php
return [
    'extensions' => [
        'ratelimit' => [
            'enabled' => true,
            'max'     => 60,   // requests allowed per window
            'window'  => 60,   // window size in seconds
        ],
    ],
];
```

The Boot hook enforces the limit globally before every request. When the limit is exceeded, the response is `429 Too Many Requests` with a plain-text body.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Limiter::exceeded($ip, $max, $window)` | `bool` | Increments the fixed-window counter for the given IP and returns `true` when the count exceeds `$max`. Uses a hashed key in APCu - the raw IP is never stored. The window resets after `$window` seconds from the first hit, not from each hit. |

Call `Limiter::exceeded()` directly for per-endpoint limits that differ from the global config - for example, stricter limits on login or password-reset endpoints.

## Example

Apply a tighter limit to a sensitive endpoint from within a controller:

```
use extensions\ratelimit\Limiter;

public function compose(Template $template): Template|Response
{
    // 5 password-reset attempts per 10 minutes
    if (Limiter::exceeded($this->request->ip(), max: 5, window: 600)) {
        return Response::text('Too Many Requests', 429);
    }

    // ... handle password reset ...
    return $template;
}
```

The global hook already covers general browsing. Use `Limiter` directly only for endpoints that need a different budget from the global config.
