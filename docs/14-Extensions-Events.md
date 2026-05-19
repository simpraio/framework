# Events

## Configuration

Create `config/events.php` in your project. The shipped config keeps this extension disabled; enable it before registering or dispatching events.

```php
return [
    'extensions' => [
        'events' => [
            'enabled' => true,
        ],
    ],
];
```

The Boot class implements `Bootable`. On boot it initialises a global `EventDispatcher` and wires it to the `Event` static facade, making it available throughout the request.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Event::on($name, $listener)` | `void` | Registers a callable listener for the named event. Multiple listeners on the same event are called in registration order. |
| `Event::dispatch($name, ...$args)` | `void` | Fires the named event, calling every registered listener with the provided arguments. Events with no listeners are silently ignored. |

## Example

Register listeners early (a good place is a Boot class that implements `Bootable`). Dispatch anywhere in the request lifecycle.

```
use extensions\events\Event;
use extensions\mail\Mail;

// Register - typically in your own Boot.php or a controller
Event::on('user.registered', function(int $userId, string $email): void {
    // send welcome email, create audit record, etc.
    Mail::message()
        ->to($email)
        ->subject('Welcome!')
        ->html('<p>Welcome aboard.</p>')
        ->send();
});

// Dispatch - in the controller that creates a new user
Event::dispatch('user.registered', $userId, $email);
```
