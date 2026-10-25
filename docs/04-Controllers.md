# Controllers

## Controller Contract

Controllers extend `core\Controller` and implement one method: `compose()`. It receives the page template and returns either a `Template` (passed to the layout) or a `Response` (sent directly, layout skipped).

```
namespace modules\blog;

use core\Controller;
use core\http\Response;
use core\Template;

final class Post extends Controller
{
    public function compose(Template $template): Template|Response
    {
        return $template->tokens([
            'TITLE' => 'Hello',
        ]);
    }
}
```

Declare controllers `final` - they are not designed to be extended. The `compose()` method is called once per request; the constructor is handled by the framework and must not be overridden.

## Available Properties

Four properties are injected by the framework and available in every controller:

```
$this->route    // resolved route for the current request
$this->view     // template loader
$this->paths    // absolute filesystem paths
$this->request  // current HTTP request
```

**Route fields** - everything the router resolved for this request:

```
$this->route->module        // e.g. 'blog'
$this->route->controller    // e.g. 'post'
$this->route->id            // e.g. '42', or null if not in the URL
$this->route->language      // e.g. 'en'
$this->route->pathId()     // 'blog/post' - module/controller combined
```

**Paths fields** - absolute paths to framework directories, useful for file I/O:

```
$this->paths->base        // project root
$this->paths->public      // public web root
$this->paths->app         // app/
$this->paths->logs        // logs/
$this->paths->templates   // templates/
$this->paths->modules     // modules/
$this->paths->config      // config/
$this->paths->extensions  // extensions/
$this->paths->bundleDir   // compiled bundle directory (cache/ or SIMPRA_BUNDLE_DIR)
```

**View methods** - load and inspect templates:

```
$this->view->load(string $name): Template    // load templates/{name}.html
$this->view->exists(string $name): bool      // check if a template file exists
```

**Request methods** - `$this->request` is a `core\http\Request`. Values are read as strings: an
array submitted under a key (`tags[]=a`) reads as the default, never as an array.

| Call | Returns | Description |
| --- | --- | --- |
| `method()` | `string` | The upper-cased method if it is one the framework handles (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`); any other method reads as `GET`. |
| `rawMethod()` | `string` | The method exactly as the client sent it, upper-cased. Use it where an unknown method must not pass for `GET`. |
| `isMethod($method)` | `bool` | Compares `method()` with `$method`, case-insensitively. |
| `uri()` | `string` | The request target as received, with its query string; `/` when absent. |
| `path()` | `string` | `uri()` without the query string or fragment. |
| `query($key, $default = null)` | `?string` | A query-string value. |
| `post($key, $default = null)` | `?string` | A form-body value. |
| `input($key, $default = null)` | `?string` | The form-body value, or the query-string value when the body has none. |
| `all()` | `array` | Form-body and query-string values together; the body wins on a shared key. |
| `header($name, $default = null)` | `?string` | A request header by name, in any case; an empty header reads as `$default`. Covers `Content-Type` and `Content-Length`, which PHP stores outside the `HTTP_*` keys. |
| `cookie($name, $default = null)` | `?string` | A cookie value. |
| `file($key)` | `?array` | The `$_FILES` entry for `$key`, or `null`. It is not validated; check `error`, `size` and the type yourself. |
| `ip()` | `string` | `REMOTE_ADDR`, or an empty string. Forwarding headers are not read: behind a proxy or load balancer this is the proxy's address. |
| `isJson()` | `bool` | Whether `Content-Type` contains `application/json`. |
| `json()` | `array` | The decoded JSON body, cached after the first successful decode. An empty body or a non-JSON `Content-Type` gives `[]`; a malformed body, or one that is not an object or array, throws `HttpException(400)` on every call. Nesting is limited by `project.max_json_depth`. |
| `rawBody()` | `string` | The request body as received. |
| `isSecure()` | `bool` | Whether `HTTPS` is set and not `off`, or the server port is 443. `X-Forwarded-Proto` is not read, so behind a TLS-terminating proxy this is `false` unless the server sets `HTTPS`. |
| `isAjax()` | `bool` | Whether `X-Requested-With` is `XMLHttpRequest`. The header is client-supplied: use it to choose a response format, never for access control. |

**The `Request` facade** - code outside a controller reaches the same request statically through
`core\Request`, which the kernel initialises for every request:

```php
use core\Request;

$page = Request::query('page', '1');
$isPost = Request::isMethod('POST');
```

`core\Request` offers `method()`, `isMethod()`, `path()`, `uri()`, `query()`, `input()`, `all()`,
`header()`, `cookie()`, `file()`, `ip()`, `isJson()`, `json()`, `rawBody()`, `isSecure()` and
`isAjax()`, each behaving as in the table above, and `instance()`, which returns the
`core\http\Request` itself for `post()` and `rawMethod()`.

```
// conditional template loading
if ($this->view->exists('blog/sidebar')) {
    $sidebar = $this->view->load('blog/sidebar')->render();
}
```

## Working with Templates

The `$template` passed to `compose()` is already loaded from `templates/{module}/{controller}.html`. If no template file exists for the route, an empty `Template` is passed instead - in that case the controller must return a `Response`, otherwise the framework returns `404`.

To load a different template - a partial, a shared component, or a template from another module - use `$this->view->load()`:

```
$partial = $this->view->load('shared/card')
    ->tokens(['TITLE' => '...'])
    ->render();

return $template->rawTokens(['CARD' => $partial]);
```

See [Templates](05-Templates.md) for the full token, block, and row rendering API.

## Returning Responses

Return a `Response` to send output directly to the client, bypassing the layout. Every static constructor accepts an optional HTTP status as its last argument.

```
Response::html('<p>fragment</p>');           // 200 text/html
Response::text('pong');                        // 200 text/plain
Response::json(['ok' => true]);               // 200 application/json
Response::json($data, 201);                    // 201 Created
Response::noContent();                          // 204 No Content, empty body
Response::redirect('/login');                  // 302 Found
Response::redirect('/moved', 301);             // 301 Moved Permanently
```

JSON is encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. Data JSON cannot carry - a string that is not valid UTF-8, `NAN` or `INF` - throws `JsonException`, which the error handler turns into a logged 500; convert legacy-encoded strings to UTF-8 before encoding them.

You can also construct a response directly when you need full control over the Content-Type:

```
return new Response($csvBody, 200, 'text/csv; charset=UTF-8');
```

## Response Methods

Chainable mutator methods - all return `$this`:

```
// override status after construction
->status(int $status): self

// replace body after construction
->body(string $body): self

// add or replace a header (name is normalized to Title-Case)
->header(string $name, string $value): self

// set a cookie
->cookie(string $name, string $value, array $options = []): self

// expire a cookie immediately
->clearCookie(string $name, string $path = '/', string $domain = ''): self
```

Read accessor - does not chain:

```
// read a header back (useful in tests or extensions)
->getHeader(string $name):  - string
```

```
// chaining example
return Response::json($data, 201)
    ->header('X-Request-Id', $requestId)
    ->cookie('theme', 'dark', [
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => true,
        'httponly' => true,
        'expires'  => time() + 86400,
    ]);

// clear a cookie on redirect
return Response::redirect('/login')
    ->clearCookie('session');
```

Header names are normalized to `Title-Case` automatically - `'x-request-id'` and `'X-Request-Id'` refer to the same header. A name must be an RFC 9110 token - letters, digits and ``!#$%&'*+-.^_`|~`` - and a value must not contain CR, LF or NUL; anything else throws `InvalidArgumentException`. The HTTP client drops request headers that break the same rule, `core\http\HeaderField`.

## Redirects

`Response::redirect()` only accepts local paths - the target must start with `/`. External URLs, protocol-relative URLs, and values containing ASCII control characters or DEL throw `InvalidArgumentException`. This includes tabs, which browsers remove while parsing URLs and which could otherwise change how a target is interpreted.

```
Response::redirect('/dashboard');          // [ok]
Response::redirect('/dashboard', 301);     // [ok] permanent
Response::redirect('https://example.com');  // [x] throws - external URL
Response::redirect('//example.com');       // [x] throws - protocol-relative
Response::redirect('');                    // [x] throws - empty
```

When the redirect destination comes from user input, validate and whitelist it in your controller before passing it to `redirect()`. The built-in checks prevent header injection but do not enforce your application's destination policy.
