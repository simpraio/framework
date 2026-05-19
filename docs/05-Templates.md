# Templates

## Syntax

Templates are plain HTML files. The framework recognises two special constructs: token placeholders and conditional blocks. Everything else is output verbatim.

```
<!-- token: replaced by the controller -->
<h1>{TITLE}</h1>

<!-- block: shown or hidden by the controller -->
{IsAdmin}
    <a href="/admin">Admin</a>
{-IsAdmin}
```

Token names are case-sensitive. Convention is `UPPER_SNAKE_CASE` for tokens and `PascalCase` for blocks, but neither is enforced. A token or block that is never replaced stays as literal text in the output - it is not removed automatically.

## Tokens

`tokens()` replaces `{KEY}` placeholders with HTML-escaped values. Every value goes through `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, UTF-8)`, and any `{` or `}` in the value are replaced with `{` and `}`. This means a user-supplied value can never form a new token or block marker in the output.

```
$template->tokens([
    'TITLE'   => $post['title'],    // HTML-escaped
    'AUTHOR'  => $post['author'],   // HTML-escaped
    'COUNT'   => (string) $count,    // values must be strings
]);
```

All values must be strings. Pass integers and floats cast to string. Returns `$this` - chainable. An empty array is a no-op.

## Raw Tokens

`rawTokens()` replaces placeholders without any escaping. Use it only for content you produced and control - rendered partials, compiled HTML, or the layout embedding a page body.

```
// layout embedding a page - safe: $template is framework output
->rawTokens(['MAIN' => $template->render()]);

// partial rendered by the controller - safe: you built this
->rawTokens(['CARD' => $cardHtml]);
```

## Conditional Blocks

Blocks wrap a section of template content between `{Name}` and `{-Name}` markers. Calling `blocks()` either keeps the inner content or removes both markers and the content entirely.

```
<!-- template -->
{IsAdmin}
    <a href="/admin/edit/{ID}">Edit</a>
{-IsAdmin}

{HasError}
    <p class="error">{ERROR_MSG}</p>
{-HasError}
```

```
// controller
$template->blocks([
    'IsAdmin'  => User::inGroup('admin'),   // bool
    'HasError' => $error !== null,   // bool expression
]);
```

The accepted values and their effect:

```
true      // show
'show'    // show
'true'    // show
false     // hide
''        // hide
'false'   // hide
'hide'    // hide
```

Blocks work across multiple lines - the pattern matches newlines. Blocks with different names are independent and can be nested. Two blocks with the same name cannot be reliably nested.

## Repeating Rows

`renderRows()` renders the same template fragment once per data row and returns the concatenated HTML string. Use it for table rows, card grids, list items, or any repeating element.

```
<!-- templates/blog/row.html -->
<li>
    <a href="/blog/post/{ID}">{TITLE}</a>
    <span>{AUTHOR}</span>
    {IsDraft}<em>Draft</em>{-IsDraft}
</li>
```

```
$row = $this->view->load('blog/row');

$html = $row->renderRows(
    rows: [
        ['ID' => '1', 'TITLE' => 'First post', 'AUTHOR' => 'Jan'],
        ['ID' => '2', 'TITLE' => 'Second post', 'AUTHOR' => 'Jan'],
    ],
    blockRows: [
        ['IsDraft' => false],
        ['IsDraft' => true],
    ],
);

return $template->rawTokens(['ROWS' => $html]);
```

The three arrays are index-aligned: `$rows[1]`, `$rawRows[1]`, and `$blockRows[1]` all apply to the second row. `$rawRows` and `$blockRows` are optional - omit them if not needed. Returns a string, not a `Template`.

## Partials

Load any template as a partial with `$this->view->load()`, populate it, call `render()`, then inject it into the parent template via `rawTokens()`.

```
$card = $this->view->load('shared/card')
    ->tokens([
        'CARD_TITLE' => $item['title'],
        'CARD_BODY'  => $item['body'],
    ])
    ->render();

return $template->rawTokens(['CARD' => $card]);
```

Partials are just templates - same file format, same token and block API, no special registration needed.

## Method Reference

```
// escaped token replacement - use for all user-facing values
->tokens(array<string, string> $tokens): Template

// unescaped replacement - use only for trusted HTML you produced
->rawTokens(array<string, string> $tokens): Template

// conditional block visibility
->blocks(array<string, bool|string> $blocks): Template

// render once per row, return concatenated HTML string
->renderRows(
    list<array<string, string>> $rows,
    list<array<string, string>> $rawRows = [],
    list<array<string, bool|string>> $blockRows = [],
): string

// return the rendered content as a string
->render(): string
```

`tokens()`, `rawTokens()`, and `blocks()` return `$this` and can be chained in any order. `renderRows()` and `render()` return strings and terminate the chain.
