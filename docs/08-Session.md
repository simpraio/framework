# Session

`core\Session` is a static facade over the session store. The framework starts the session automatically - you do not call `session_start()`. Session cookie behaviour (name, lifetime, `Secure`, `HttpOnly`, `SameSite`) is controlled by `config/session.php`.

## Reading and writing

```php
// Store a value
Session::set('user_id', 42);

// Read a value, with an optional default
$id = Session::get('user_id');
$locale = Session::get('locale', 'en');

// Check existence
if (Session::has('cart')) { ... }

// Remove a key
Session::forget('cart');

// Read and immediately remove (useful for one-time values)
$returnUrl = Session::pull('return_url', '/dashboard');
```

## Regenerating the session ID

Always regenerate after privilege changes (login, sudo, role escalation):

```php
Session::regenerate();           // regenerate and delete old session data
Session::regenerate(false);      // regenerate but keep old data (rare)
```

The auth extension calls `regenerate()` automatically on login.

## Destroying the session

```php
Session::destroy();   // clear all data and delete the session cookie
```

Use on logout after clearing auth state. The auth extension handles this for you when you call `Auth::logout()`.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Session::get($key, $default)` | `mixed` | Return the value for `$key`, or `$default` if absent. |
| `Session::set($key, $value)` | `void` | Store a value under `$key`. |
| `Session::has($key)` | `bool` | Return `true` if `$key` exists in the session. |
| `Session::forget($key)` | `void` | Remove `$key` from the session. |
| `Session::pull($key, $default)` | `mixed` | Return the value for `$key` and remove it. |
| `Session::regenerate($deleteOld)` | `void` | Issue a new session ID. Deletes old data when `$deleteOld` is `true` (default). |
| `Session::destroy()` | `void` | Clear all session data and delete the session. |
| `Session::isStarted()` | `bool` | Return `true` if the session has been started. |

## Configuration

Session cookie settings live in `config/session.php`:

```php
return [
    'session' => [
        'name'      => 'sid',
        'lifetime'  => 0,          // 0 = until browser close
        'path'      => '/',
        'domain'    => '',
        'secure'    => true,
        'http_only' => true,
        'same_site' => 'Lax',
    ],
];
```

See [Configuration](02-Configuration.md) and [Security](25-Security.md) for guidance on the `secure`, `http_only`, and `same_site` settings.
