# Profiler

## Configuration

Create `config/profiler.php` in your project. The profiler is disabled by default.

```php
return [
    'extensions' => [
        'profiler' => [
            'enabled' => false,  // enable only in development
        ],
    ],
];
```

When enabled, the Boot hook's `after()` method appends the timing report as an HTML comment at the end of every `text/html` response. Non-HTML responses are not modified.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Profiler::mark($label)` | `void` | Records a named timestamp at the current moment. Labels are free-form strings - use them to describe what just completed. |
| `Profiler::report()` | `string` | Returns the timing report as an HTML comment string. Called automatically by the Boot hook - you rarely need this directly. |

The report lists each mark with time elapsed since the start of the request and since the previous mark:

```
<!--
[Profiler]
  auth.check                                    2.341 ms  (+ 2.341 ms)
  db.user_query                                 4.872 ms  (+ 2.531 ms)
  template.render                               6.104 ms  (+ 1.232 ms)
  TOTAL                                         6.104 ms
-->
```

## Example

Place marks around the slow parts of your controller:

```
use extensions\profiler\Profiler;

public function compose(Template $template): Template|Response
{
    Profiler::mark('before db');
    $rows = Db::select('SELECT * FROM `products` WHERE `active` = 1');
    Profiler::mark('after db');

    // ... build template ...

    Profiler::mark('template ready');
    return $template;
}
```

The Boot hook appends the comment automatically - no need to call `report()` yourself.
