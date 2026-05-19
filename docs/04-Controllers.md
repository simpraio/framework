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

**Request methods** - read query/post input, headers, JSON bodies, files, cookies, client IP, and method information:

```
$this->request->isMethod('POST'): bool
$this->request->input('email'):  - string
$this->request->all(): array
$this->request->json(): array
$this->request->header('Accept'):  - string
$this->request->ip(): string
```

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

JSON is encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. If encoding fails, `null` is sent as the body.

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

Header names are normalized to `Title-Case` automatically - `'x-request-id'` and `'X-Request-Id'` refer to the same header. Header names or values containing CR, LF, or NUL throw `InvalidArgumentException`.

## Redirects

`Response::redirect()` only accepts local paths - the target must start with `/`. External URLs, protocol-relative URLs, and values containing CR, LF, or NUL throw `InvalidArgumentException`.

```
Response::redirect('/dashboard');          // [ok]
Response::redirect('/dashboard', 301);     // [ok] permanent
Response::redirect('https://example.com');  // [x] throws - external URL
Response::redirect('//example.com');       // [x] throws - protocol-relative
Response::redirect('');                    // [x] throws - empty
```

When the redirect destination comes from user input, validate and whitelist it in your controller before passing it to `redirect()`. The built-in checks prevent header injection but do not enforce your application's destination policy.
