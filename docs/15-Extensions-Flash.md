# Flash

## Configuration

Create `config/flash.php` in your project. The shipped config keeps this extension disabled; enable it before using the flash facade or template block.

```php
return [
    'extensions' => [
        'flash' => [
            'enabled' => true,
        ],
    ],
];
```

Flash data lives in the session and is consumed on the next request. The Boot class implements `Contributor`, exposing the `hasErrors` block to all templates.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Flash::withErrors($errors, $keep)` | `void` | Stores a field-name -> message error map in the session. `$keep` is an optional list of request field names to persist as old input - only listed fields are stored, never passwords or tokens. |
| `Flash::errors()` | `array` | Returns the error map from the previous request. Empty if no errors were flashed. |
| `Flash::submitted()` | `array` | Returns the old input map from the previous request, keyed by field name. |
| `Flash::hasErrors()` | `bool` | Returns `true` if any errors were flashed. Also exposed as the `hasErrors` template block by the contributor. |

The contributor injects one block into every template:

```
{hasErrors}
    <div class="alert">Please correct the errors below.</div>
{-hasErrors}
```

## Example

Validate a form, flash errors and old input on failure, then redirect back. On the next request the template has access to both:

```
// In the POST handler controller
use extensions\flash\Flash;
use extensions\validation\Validator;

$v = new Validator($this->request->all());
$email = $v->email('email');
$name  = $v->string('name', min: 1, max: 100, trim: true);

if (!$v->ok()) {
    Flash::withErrors($v->errors(), keep: ['email', 'name']);
    return Response::redirect('/signup/');
}
```

```
// In the GET controller that renders the form
$errors    = Flash::errors();    // e.g. ['email' => 'email'] - validator error code
$submitted = Flash::submitted(); // e.g. ['name' => 'Jan']
```
