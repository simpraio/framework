# Layout

## How It Works

After a controller's `compose()` returns a `Template`, the framework calls `modules\Layout::compose()` and passes the page template as its argument. The layout controller wraps it in a shared HTML shell and returns the final `Response`.

```
request -> router -> controller -> Template
                                     v
                              Layout::compose()
                                     v
                         Extensions contributors
                                     v
                              Response::html()
```

If the controller returns a `Response` directly (redirect, JSON, file download), the layout is skipped entirely - the response goes straight to the client.

## Layout Controller

The layout controller lives at `modules/Layout.php` - in the root `modules\` namespace, not inside a module subdirectory. It extends `core\Controller` and implements the same `compose()` contract.

The shipped layout is intentionally minimal. It sets a page title, description, one CSS asset version token, and the rendered page body:

```
namespace modules;

use core\Controller;
use core\http\Response;
use core\Template;
use core\tools\Assets;

final class Layout extends Controller
{
    public function compose(Template $template): Template|Response
    {
        $assets = new Assets($this->paths->public);

        return $this->view->load('layout')
            ->tokens([
                'HEAD_TITLE' => 'SIMPRA',
                'HEAD_DESCRIPTION' => 'Minimal PHP 8.4+ framework for small websites, internal tools, and simple SaaS projects.',
                'VERSION' => $assets->version('/assets/css/common.css'),
            ])
            ->rawTokens([
                'MAIN' => $template->render(),
            ]);
    }
}
```

The `$template` argument is the _page_ template - already populated by the page controller. Call `render()` on it and inject the result as a raw token. The same injected controller properties are available here: `$this->view`, `$this->paths`, `$this->route`, and `$this->request`.

The layout can also return a `Response` - useful for enforcing auth, redirecting maintenance pages, or injecting headers globally.

You are expected to modify `modules/Layout.php` and `templates/layout.html` for each project. Common additions include language tokens, JavaScript asset versions, generation timing, analytics snippets, or metadata contributed by extensions.

**Asset versioning** - `core\tools\Assets::version(string $relativePath)` returns the file's `filemtime` as a string, cached via APCu for 60 seconds. Pass it as a query string to break browser caches when the file changes:

```
$assets = new Assets($this->paths->public);
$assets->version('/assets/css/common.css');  // e.g. '1715000000'
// used as: /assets/css/common.css - v=1715000000
```

If the file does not exist, `version()` returns `'1'` as a fallback.

## Layout Tokens

The default layout template (`templates/layout.html`) uses only these tokens:

```
{HEAD_TITLE}       // default <title> content from modules/Layout.php
{HEAD_DESCRIPTION} // default <meta name="description"> content
{VERSION}          // mtime-based cache-busting version for common.css
{MAIN}             // rendered page body (raw - do not escape)
```

You can add any other tokens your project needs in `modules/Layout.php`.

**Optional layout-controller tokens** - examples you may add directly in `compose()`:

```
{LANGUAGE}         // current language code, e.g. 'en'
{CSS_VERSION}      // mtime-based cache-busting version for common.css
{JS_VERSION}       // mtime-based cache-busting version for your app JavaScript asset
{GENERATION_TIME}  // render time in milliseconds
{MAIN}             // rendered page body (raw - do not escape)
```

**Optional extension contributor tokens** - injected after `compose()` returns if the relevant extension is enabled (see [Contributors](06-Layout.md#contributors) below):

```
{HEAD_TITLE}       // <title> content - provided by the SEO extension
{HEAD_DESCRIPTION} // <meta name="description"> - provided by the SEO extension
{CANONICAL_URL}    // <link rel="canonical"> - provided by the SEO extension
```

## Contributors

After `Layout::compose()` returns a `Template`, the framework passes it through all registered extension contributors before rendering. Each contributor can inject additional tokens, raw tokens, or blocks into the layout template.

```php
// extensions/seo/Boot.php - simplified contributor example
public function contribute(Route $route): array
{
    $seo = Store::page($route);
    $config = Config::enabled();
    $project = CoreConfig::project();
    $canonical = $seo['canonical_url'] ?: rtrim($project->url, '/') . (Aliases::uri($route) ?: Request::path());

    return [
        'tokens' => [
            'HEAD_TITLE'       => $seo['title'] ?: $config->title,
            'HEAD_DESCRIPTION' => $seo['description'] ?: $config->description,
            'CANONICAL_URL'    => $canonical,
        ],
    ];
}
```

The returned array may have three keys - `tokens`, `rawTokens`, and `blocks` - all optional. Contributors run in registration order. See [Extensions](10-Extensions.md) for how to implement the `Contributor` interface.

## Making Layout Optional

The layout controller is not required. If `modules/Layout.php` does not exist, the framework renders the page template directly - no wrapping, no shared shell.

```
# with layout
modules/Layout.php exists    ->  page body wrapped in templates/layout.html

# without layout
modules/Layout.php absent    ->  page template rendered as-is, sent as text/html
```

Extension contributors still run in both cases - they apply to whatever template is being rendered. This is useful for minimal deployments (APIs, microservices) where a full HTML shell is not needed.
