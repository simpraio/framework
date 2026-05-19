# Mail

## Configuration

Create `config/mail.php` in your project. The example below shows the shipped project config. In the typed config fallback, mail defaults to enabled, so keep an explicit `'enabled' => false` until your transport is configured.

```php
return [
    'extensions' => [
        'mail' => [
            'enabled'    => false,
            'transport'  => 'smtp',      // 'smtp' or 'native'
            'from_email' => '',
            'from_name'  => '',
            'smtp' => [
                'host'       => '',
                'port'       => 587,
                'encryption' => 'tls',    // 'tls', 'ssl', or 'none'
                'auth'       => true,
                'username'   => '',
                'password'   => '',
                'timeout'    => 30,
            ],
        ],
    ],
];
```

The `native` transport uses PHP's built-in `mail()` function. Use `smtp` for all production deployments - it gives you explicit control over authentication, encryption, and the sending server.

## Public API

Build a message with the fluent `Message` API, then send it:

| Call | Returns | Description |
| --- | --- | --- |
| `Mail::message()` | `Message` | Creates a new blank message. |
| `Mail::send($message)` | `void` | Builds and delivers the message via the configured transport. Throws `RuntimeException` if the from address is missing. |
| `$msg->to($address)` | `Message` | Sets recipients. Accepts a single address, a comma-separated string, or an array. RFC 5321 name+address syntax (`"Name <email>"`) is supported. |
| `$msg->subject($text)` | `Message` | Sets the subject. Non-ASCII text is encoded automatically with `= - UTF-8 - B - ... - =`. |
| `$msg->html($html)` | `Message` | Sets the HTML body. A plain-text fallback is generated automatically via `strip_tags()` unless `->text()` is called explicitly. |
| `$msg->text($text)` | `Message` | Sets an explicit plain-text body, overriding the auto-generated fallback. |
| `$msg->from($email, $name)` | `Message` | Overrides the config-level from address for this message only. |
| `$msg->attach($name, $data)` | `Message` | Attaches a file. `$data` is the raw binary content. MIME type is detected automatically. |
| `$msg->header($name, $value)` | `Message` | Adds a custom header. Reserved headers (From, To, Subject, Date, etc.), empty values, and names or values containing CR/LF are silently ignored. |
| `$msg->send()` | `void` | Shorthand for `Mail::send($this)`. |

## Example

Send a transactional email with an attachment:

```
use extensions\mail\Mail;

Mail::message()
    ->to('customer@example.com')
    ->subject('Your invoice #' . $invoiceId)
    ->html('<p>Please find your invoice attached.</p>')
    ->attach('invoice.pdf', $pdfBytes)
    ->send();
```

Send to multiple recipients with a custom reply-to header:

```
Mail::message()
    ->to(['alice@example.com', 'bob@example.com'])
    ->subject('Team update')
    ->html($htmlBody)
    ->header('Reply-To', 'noreply@example.com')
    ->send();
```
