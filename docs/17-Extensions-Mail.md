# Mail

## Configuration

Create `config/mail.php` in your project. The example below shows the shipped project config. In the typed config fallback, mail defaults to enabled, so keep an explicit `'enabled' => false` until your transport is configured.

The mail extension requires PHP `mbstring`, which Simpra uses for bounded UTF-8 header encoding.

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
| `Mail::send($message)` | `void` | Builds and delivers the message via the configured transport. Throws `RuntimeException` if the from address or a required runtime dependency is missing. |
| `$msg->to($address)` | `Message` | Sets recipients. Accepts a single address, a comma-separated string, or an array. RFC 5322 name+address syntax (`"Name <email>"`) is supported; generated `To` lines are folded between addresses. |
| `$msg->subject($text)` | `Message` | Sets the subject. Non-ASCII or oversized text is encoded into bounded RFC 2047 encoded-words; short ASCII text stays unchanged. Invalid UTF-8, a line break, or any other control character except tab is refused with `InvalidArgumentException('INVALID_MAIL_HEADER_VALUE')` rather than encoded or flattened. |
| `$msg->html($html)` | `Message` | Sets the HTML body. A plain-text fallback is generated automatically via `strip_tags()` unless `->text()` is called explicitly. |
| `$msg->text($text)` | `Message` | Sets an explicit plain-text body, overriding the auto-generated fallback. |
| `$msg->from($email, $name)` | `Message` | Overrides the config-level from address for this message only. The address is validated; an invalid one falls back to the configured address. `$name` is safely quoted or encoded as a display name and never affects the SMTP envelope sender. |
| `$msg->attach($name, $data)` | `Message` | Attaches a file. `$data` is the raw binary content. MIME type is detected automatically, and long UTF-8 filenames use bounded RFC 2231 continuations. |
| `$msg->header($name, $value)` | `Message` | Adds a custom header. Silently ignored are: reserved headers (From, To, Subject, Date, etc.), empty values, names or values containing CR/LF, names that are not a valid RFC 5322 field name, values carrying anything but printable ASCII and tab - a control character, or raw Unicode, which would need SMTPUTF8 this client does not negotiate - and headers whose rendered line would exceed 998 octets. Pass the ASCII form the header's own grammar calls for. An RFC 2047 encoded-word (`=?UTF-8?B?...?=`) is valid only where that grammar admits one - an unstructured value, a comment, or a display-name phrase - and never inside an address, token or quoted string (RFC 2047 section 5), so encoding a whole `Reply-To` value yields a field with no mailbox in it. The framework does not encode custom fields for you, because only the caller knows the field's grammar; subjects, display names and attachment filenames it does own, and encodes. A custom header is dropped rather than refused, so one bad value never stops the message. |
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
