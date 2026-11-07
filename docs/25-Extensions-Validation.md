# Validation

## Configuration

The validation extension has no `Boot.php` and no configuration file. It is a utility-only extension - instantiate `Validator` directly in any controller. No setup required.

```
use extensions\validation\Validator;

$v = new Validator($this->request->all());
```

## Public API

Instantiate `Validator` with any array (POST body, query params, API payload). Call rule methods to declare what each field must be. Collect results with `ok()`, `errors()`, and `values()`.

`optional: true` excuses only an absent value: a missing key, `null` or `''`, and whitespace alone where the rule trims (`string(trim: true)`, `pattern()` unless `trim: false`, `email()` and `bool()`). `[]` is absent too, except for `string()` and `pattern()`: they take text only and refuse any array, empty or not, with `string`. A present value of the wrong type is an error, never absent: an optional `email()`, `uuid()` or `datetime()` given a non-empty array, a number or a boolean fails with its own code.

| Call | Returns | Description |
| --- | --- | --- |
| `->int($name, $min, $max, $optional)` | `?int` | Validates an integer field. Optionally enforces a min/max range. |
| `->float($name, $min, $max, $optional)` | `?float` | Validates a float field. Optionally enforces a min/max range. |
| `->string($name, $min, $max, $trim, $optional)` | `?string` | Validates a string field. Optionally enforces min/max length in characters and, when `trim: true` is passed, trims whitespace. A string or an integer is accepted (an integer as its decimal string); a float, a boolean, an array, or a value that is not valid UTF-8 fails with `string`. |
| `->bool($name, $optional)` | `?bool` | Validates a boolean field with `FILTER_VALIDATE_BOOL`. True: `'1'`, `'true'`, `'on'`, `'yes'`; false: `'0'`, `'false'`, `'off'`, `'no'`. The strings are case-insensitive and may carry surrounding spaces, tabs or line breaks. A real `true`/`false`, the integers `1`/`0` and the floats `1.0`/`0.0` are accepted too. Whitespace alone is blank, not false: `required` when the field is required, absent when optional. Any other value, such as `'y'`, `'2'`, `'1.0'` or an array, fails with `bool`. |
| `->email($name, $optional)` | `?string` | Validates a trimmed email address using `FILTER_VALIDATE_EMAIL`, and additionally refuses any control character, space, `"`, `\`, `<` or `>`. The filter admits those only in a quoted local part, where a quoted-pair can carry a line break into an SMTP command or a header, so quoted local parts such as `"john doe"@example.com` fail with `email`. |
| `->pattern($name, $pattern, $trim, $optional)` | `?string` | Validates a string against a regex pattern. A value that makes PCRE give up on its backtrack, recursion or JIT stack limit fails with `pattern`; a malformed pattern throws `InvalidArgumentException`, with or without the framework's error handler. |
| `->in($name, $choices, $optional)` | `mixed` | Validates that the field value is one of `$choices`. A scalar value and each choice are compared as strings, so `'2'` matches `2` and JSON `true` matches `'1'`; a non-scalar never matches. Returns the matched choice, with the choice's type, not the input. |
| `->uuid($name, $version, $optional)` | `?string` | Validates a UUID string. Optionally restricts to a specific version (1-8); any other version throws `InvalidArgumentException`. |
| `->datetime($name, $format, $optional)` | `?DateTimeImmutable` | Validates and parses a datetime string using the given format (default `Y-m-d H:i:s`). A field the format does not name is zero rather than the current time: `Y-m-d` gives midnight, `H:i` gives that time on 1970-01-01. A format already containing `!` or `\|` is used as given. The value must format back to exactly the input. |
| `->custom($name, $check, $error, $optional)` | `mixed` | Validates using a callable. The callable receives the raw value and must return `bool`. |
| `->ok()` | `bool` | Returns `true` if no rule has failed so far. |
| `->errors()` | `array` | Returns a field name -> error code map for every failed rule. Codes include values such as `required`, `email`, `min`, `max`, `pattern`, and custom codes you pass to `custom()`. |
| `->values()` | `array` | Returns a field name -> typed value map for every field that passed validation. A field that failed any of its rules has no value here, whichever rule ran first, so a field is never in both `errors()` and `values()`. |

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
