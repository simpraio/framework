# Extensions

## How Extensions Work

Extensions are discovered automatically from the `extensions/` directory. Each extension is a folder with a `Boot.php` class that extends `core\extension\Boot`. The Boot class can implement any combination of three interfaces:

```
Hook          // intercept requests before and after dispatch
Contributor   // inject tokens and blocks into the layout template
Bootable      // run one-time setup on every request
```

Extensions that implement none of these - utility-only extensions like `mail` or `validation` - do not need a `Boot.php` at all. The loader skips any extension folder without one. They are used directly in controllers as static facades or plain classes.

## Directory Structure

An extension lives entirely in its own folder under `extensions/`. The framework requires only `Boot.php`; `Config.php` is optional.

```
extensions/
`--- myext/
    |--- Boot.php      // required - extends core\extension\Boot
    `--- Config.php    // optional - loaded alongside Boot.php
```

A minimal Boot class with all three interfaces:

```php
namespace extensions\myext;

use core\extension\Boot;
use core\extension\Bootable;
use core\extension\Contributor;
use core\extension\Hook;
use core\http\Request;
use core\http\Response;
use core\http\Route;

final class Boot extends \core\extension\Boot
    implements Bootable, Hook, Contributor
{
    public function boot(): void { }

    public function before(Request $request, Route $route):  - Response
    {
        return null;
    }

    public function after(Request $request, Route $route, Response $response): void { }

    public function contribute(Route $route): array
    {
        return [];
    }
}
```

## Hook

Implementing `Hook` gives the extension two entry points per request. Hooks run in filesystem discovery order.

```
// runs before dispatch - return a Response to short-circuit, null to continue
public function before(Request $request, Route $route):  - Response

// runs after the final response is ready - use to add headers or log
public function after(Request $request, Route $route, Response $response): void
```

**`before()`** - returning a non-null `Response` skips all remaining hooks, the controller, and the layout. The first hook to return a Response wins; subsequent hooks do not run.

**`after()`** - always runs on every hook after the response is produced. The `$response` object is mutable - call `->header()`, `->cookie()`, or `->body()` to modify it in place. All hooks see the same instance.

```
// before: block a route for unauthenticated users
public function before(Request $request, Route $route):  - Response
{
    if ($route->pathId() === 'admin/dashboard' && !User::inGroup('admin')) {
        return Response::redirect('/login');
    }
    return null;
}

// after: add a header to every response
public function after(Request $request, Route $route, Response $response): void
{
    $response->header('X-Powered-By', 'Simpra');
}
```

## Contributor

Implementing `Contributor` lets the extension inject tokens and blocks into the layout template after `Layout::compose()` returns. Contributors run in filesystem discovery order.

```
public function contribute(Route $route): array
```

The returned array may have up to three keys - all optional:

```php
return [
    'tokens'    => ['HEAD_TITLE' => 'My page'],           // HTML-escaped
    'rawTokens' => ['WIDGET'    => $renderedHtml],         // unescaped
    'blocks'    => ['IsAuthenticated' => User::isAuthenticated()], // bool
];
```

## Bootable

Implementing `Bootable` adds a `boot()` method that runs once per request during extension initialisation, before any hooks or contributors.

```
public function boot(): void
```

Use it for one-time setup that must happen early: registering error handlers, initialising static facades, or wiring event listeners. The `error-log` extension uses it to attach an error handler callback; the `events` extension uses it to set up the global dispatcher.

## Enabling & Disabling

The shipped project config decides what loads. Most bundled extensions are disabled in `config/*.php`; enable only the ones your app needs:

```
'extensions' => [
    'csrf' => ['enabled' => true],
    'ratelimit' => ['enabled' => true],
],
```

Discovery order follows the filesystem (`glob()` order, typically alphabetical). Hooks and contributors execute in that same order - the first `before()` hook to return a non-null Response wins.

## Bundled Extensions

| Extension | Interfaces | Purpose |
| --- | --- | --- |
| `auth` | Hook, Contributor | Session-based user authentication and per-route access control. Provides `User` static facade and `{USER_ID}`, `{USER_GROUP}` tokens. |
| `csrf` | Hook | CSRF token validation on mutating requests (POST, PUT, PATCH, DELETE). Replaces `{_csrf}` placeholder with the token in HTML responses. |
| `error-log` | Bootable, Hook | Writes unhandled exceptions to the `error_log` database table. Prunes old entries daily. Schema: `tools/schema/error-log.sql`. |
| `events` | Bootable | Initialises a global event dispatcher. Use the `Event` static facade to register listeners (`Event::on()`) and dispatch (`Event::dispatch()`). |
| `flash` | Contributor | Session flash messages for validation errors and old input. Provides `Flash` static facade and the `{hasErrors}` block. |
| `http-client` | - | cURL-based HTTP client with retry logic, TLS verification, egress checks, and response size limits. No hooks - purely a utility class (`HttpClient::get()`, `HttpClient::post()`). |
| `mail` | - | Email sending via SMTP or PHP `mail()`. No hooks - use the `Mail` static facade to compose and send messages from controllers. |
| `profiler` | Hook | Appends a request-timing report as an HTML comment to every HTML response. Disabled by default - enable only in development. |
| `ratelimit` | Hook | IP-based rate limiting. Returns `429 Too Many Requests` when the configured request count per window is exceeded. |
| `registry` | Contributor | Loads dynamic key-value settings from the `registry` database table per language. Use the `Registry` or `Settings` facade to read values in controllers. Schema: `tools/schema/registry.sql`. |
| `security` | Hook | Adds HTTP security headers (CSP, X-Frame-Options, Permissions-Policy, etc.) to every response via `after()`. Configurable per header in `config/security.php`. |
| `seo` | Contributor | Injects per-route title, description, and canonical URL into the layout from the `seo` database table, with config fallbacks. Schema: `tools/schema/seo.sql`. |
| `translation` | Contributor | Loads translation strings from the `translation` database table and injects them as raw tokens into the layout and page templates. Schema: `tools/schema/translation.sql`. |
| `validation` | - | Chainable form validator. No hooks - instantiate `Validator` directly in a controller with the input array and call rule methods to collect errors. |
