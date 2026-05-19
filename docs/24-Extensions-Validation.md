# Validation

## Configuration

The validation extension has no `Boot.php` and no configuration file. It is a utility-only extension - instantiate `Validator` directly in any controller. No setup required.

```
use extensions\validation\Validator;

$v = new Validator($this->request->all());
```

## Public API

Instantiate `Validator` with any array (POST body, query params, API payload). Call rule methods to declare what each field must be. Collect results with `ok()`, `errors()`, and `values()`.

| Call | Returns | Description |
| --- | --- | --- |
| `->int($name, $min, $max, $optional)` | ` - int` | Validates an integer field. Optionally enforces a min/max range. |
| `->float($name, $min, $max, $optional)` | ` - float` | Validates a float field. Optionally enforces a min/max range. |
| `->string($name, $min, $max, $trim, $optional)` | ` - string` | Validates a string field. Optionally enforces min/max length and trims whitespace. |
| `->bool($name, $optional)` | ` - bool` | Validates a boolean field (accepts `true`, `false`, `1`, `0`, `'true'`, `'false'`). |
| `->email($name, $optional)` | ` - string` | Validates an email address using `FILTER_VALIDATE_EMAIL`. |
| `->pattern($name, $pattern, $trim, $optional)` | ` - string` | Validates a string against a regex pattern. |
| `->in($name, $choices, $optional)` | `mixed` | Validates that the field value is in the given array of allowed values (strict comparison). |
| `->uuid($name, $version, $optional)` | ` - string` | Validates a UUID string. Optionally restricts to a specific version (1-5). |
| `->datetime($name, $format, $optional)` | ` - DateTimeImmutable` | Validates and parses a datetime string using the given format (default `Y-m-d H:i:s`). |
| `->custom($name, $check, $error, $optional)` | `mixed` | Validates using a callable. The callable receives the raw value and must return `bool`. |
| `->ok()` | `bool` | Returns `true` if no rule has failed so far. |
| `->errors()` | `array` | Returns a field name -> error code map for every failed rule. Codes include values such as `required`, `email`, `min`, `max`, `pattern`, and custom codes you pass to `custom()`. |
| `->values()` | `array` | Returns a field name -> typed value map for every field that passed validation. |

## Example

Validate a registration form in a POST controller, then flash errors or proceed:

```
use extensions\flash\Flash;
use extensions\validation\Validator;

public function compose(Template $template): Template|Response
{
    if (!$this->request->isMethod('POST')) {
        return $template;
    }

    $v = new Validator($this->request->all());

    $name  = $v->string('name',  min: 2, max: 100, trim: true);
    $email = $v->email('email');
    $age   = $v->int('age', min: 18, max: 120);
    $role  = $v->in('role', ['admin', 'editor', 'viewer']);

    if (!$v->ok()) {
        Flash::withErrors($v->errors(), keep: ['name', 'email', 'role']);
        return Response::redirect('/register/');
    }

    // all fields are typed and safe to use
    UserService::create($name, $email, $age, $role);
    return Response::redirect('/dashboard/');
}
```

Custom rule - validate that a username is not already taken:

```
$username = $v->custom(
    'username',
    fn(mixed $val): bool => is_string($val) && !Db::row(
        'SELECT 1 FROM `auth_user` WHERE `username` = :u',
        ['u' => $val]
    ),
    error: 'Username is already taken.',
);
```
