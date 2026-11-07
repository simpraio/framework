# Mail

## Configuration

Create `config/mail.php` in your project. The example below shows the shipped project config. Mail is off unless a config layer sets `'enabled' => true`; leave it off until your transport is configured.

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

### SMTP transport

- `encryption` is `tls` (STARTTLS on the configured port, the default), `ssl` (TLS from the first byte, usually port 465) or `none`, matched case-insensitively. Any other value - a typo such as `sll` - is refused when the message is sent, with `Config: 'extensions.mail.smtp.encryption' must be one of none, ssl, tls`, rather than falling back to a mode you did not choose.
- `timeout` (seconds) bounds the connection attempt and each server reply as a whole, not each read: a server that trickles a reply is cut off at the timeout with `SMTP_TIMEOUT`. A reply larger than 64 KiB is refused with `SMTP_RESPONSE_TOO_LARGE`.
- A failure throws a `RuntimeException` carrying an `SMTP_*` code, such as `SMTP_CONNECT_FAILED`, `SMTP_TLS_FAILED`, `SMTP_EMPTY_RESPONSE` or `SMTP_WRITE_FAILED`, or `SMTP: <reply>` when the server refuses a command. Connection and handshake failures carry their code under the framework's error handler too, not an `ErrorException`.
- STARTTLS failures include a reason after `SMTP_TLS_FAILED: `: the first TLS warning, bounded to 512 bytes with control characters replaced by spaces, or a fallback when PHP supplies none. Unread plaintext after the STARTTLS reply is refused before the handshake and named separately. Match the code prefix rather than the whole diagnostic, whose wording depends on PHP and OpenSSL.
- Connection failures include a reason after `SMTP_CONNECT_FAILED: ` under the same bounds: the operating system's error message, or, when there is none - an `ssl` handshake or certificate failure, for example - the first connection warning, which carries the OpenSSL diagnostic. With neither, the code stands alone as `SMTP_CONNECT_FAILED`.
- The server's `250` to the message data is the point of delivery. Any other `2xx` reply there counts as accepted too: RFC 5321 requires `250`, but reporting another success code as a failure would make a retrying caller send the message twice. What follows it - `QUIT`, and closing the connection - is best effort: a server that hangs up instead of answering `QUIT` does not make `Mail::send()` throw, so a caller that retries on failure does not send the message twice.
- Once the message data and its terminating `.` have been sent, the server may have committed the message even if its reply never arrives. A connection lost, a timeout, or an unreadable reply at that point throws `SMTP_DELIVERY_UNKNOWN: <cause>` (for example `SMTP_DELIVERY_UNKNOWN: SMTP_TIMEOUT`), with the original failure as the exception's `getPrevious()`. Do not retry it automatically: SMTP has no idempotency, so a retry can deliver the message twice. Record it for follow-up and check out of band - the relay's logs or the recipient's mailbox - before sending again anything that must not arrive twice. A `4xx` or `5xx` reply refusing the data (`SMTP: 451 ...`, `SMTP: 554 ...`) is a definite failure, and every failure before the data is sent keeps its own code; neither delivered the message.

## Public API

Build a message with the fluent `Message` API, then send it:

| Call | Returns | Description |
| --- | --- | --- |
| `Mail::message()` | `Message` | Creates a new blank message. |
| `Mail::send($message)` | `void` | Builds and delivers the message via the configured transport. Throws `RuntimeException` if the from address or a required runtime dependency is missing. |
| `$msg->to($address)` | `Message` | Sets recipients. Accepts a single address, a comma-separated string, or an array. RFC 5322 name+address syntax (`Name <email>`) is supported, and the address is the angle-addr that ends the item, so in `"Name <a@x.test>" <b@x.test>` it is `b@x.test`. A comma in a quoted display name does not separate items, and a backslash there escapes the next character. Comments are not read: `Name <a@x.test> (work)` is not a valid item. Empty items are skipped; any other item that is not a valid address - an address holding a control character, a blank angle-addr, or a quote left open among them - refuses the whole call with `InvalidArgumentException('INVALID_RECIPIENT')` and leaves the earlier recipients in place, rather than sending to the rest. So does an item where a comma was missed and the earlier address is outside quotes: an address or angle-addr before the angle-addr, as in `jan@x.test Eva <eva@x.test>`, or a quote after an address. Between display names holding stray quotes, as in `a" jan@x.test b" <eva@x.test>`, a missed comma reads as part of one display name and the item goes to `eva@x.test` alone: it has the same form as a display name quoting an address among other words, such as `Help "jan@x.test desk" <eva@x.test>`. An address used as a display name must be quoted: `"jan@x.test" <jan@x.test>`. In a string, an item whose quoted strings hold a comma and an `@` between them refuses the call as well, since that is what stray quotes in display names make of the recipients between them. An array item is one address: a comma outside quotes in it refuses the call. Display names are not sent - the `To` header lists the addresses alone - so when they come from users, pass an array of bare addresses. Input whose items are all blank throws `InvalidArgumentException('NO_VALID_RECIPIENT')`. A string, or an array item, longer than 1 MiB (1,048,576 bytes) refuses the call before it is read. Generated `To` lines are folded between addresses. |
| `$msg->subject($text)` | `Message` | Sets the subject. Non-ASCII or oversized text is encoded into bounded RFC 2047 encoded-words; short ASCII text stays unchanged. Invalid UTF-8, a line break, or any other control character except tab is refused with `InvalidArgumentException('INVALID_MAIL_HEADER_VALUE')` rather than encoded or flattened. This setter only trims spaces and tabs - the refusal happens when the message is built, so catch it around `Mail::send()` or `Composer::build()`, not around this call. |
| `$msg->html($html)` | `Message` | Sets the HTML body. A plain-text fallback is generated automatically via `strip_tags()` unless `->text()` is called explicitly. |
| `$msg->text($text)` | `Message` | Sets an explicit plain-text body, overriding the auto-generated fallback. With both bodies the message is `multipart/alternative`, text first and HTML last; with only one of them - text and no `->html()`, or HTML with an explicit `->text('')` - it is that single part, because a client shows the last alternative it can render and an empty one would display a blank message. Attachments wrap whichever of these in `multipart/mixed`. |
| `$msg->from($email, $name)` | `Message` | Overrides the config-level from address for this message only. The address is validated; an invalid one falls back to the configured address. `$name` is safely quoted or encoded as a display name and never affects the SMTP envelope sender. |
| `$msg->attach($name, $data)` | `Message` | Attaches a file. `$data` is the raw binary content. MIME type is detected automatically, and long UTF-8 filenames use bounded RFC 2231 continuations. |
| `$msg->header($name, $value)` | `Message` | Adds a custom header. Silently ignored are: reserved headers (From, To, Cc, Bcc, Subject, Date, Message-ID, MIME-Version, Content-Type, Content-Transfer-Encoding), empty values, names or values containing CR/LF, names that are not a valid RFC 5322 field name or that start with a dot (an SMTP hop strips one leading dot from a line, so `.From` would arrive as `From`), values carrying anything but printable ASCII and tab - a control character, or raw Unicode, which would need SMTPUTF8 this client does not negotiate - and headers whose rendered line would exceed 998 octets. Pass the ASCII form the header's own grammar calls for. An RFC 2047 encoded-word (`=?UTF-8?B?...?=`) is valid only where that grammar admits one - an unstructured value, a comment, or a display-name phrase - and never inside an address, token or quoted string (RFC 2047 section 5), so encoding a whole `Reply-To` value yields a field with no mailbox in it. The framework does not encode custom fields for you, because only the caller knows the field's grammar; subjects, display names and attachment filenames it does own, and encodes. A custom header is dropped rather than refused, so one bad value never stops the message. There is no Cc or Bcc: SMTP delivers only to the `->to()` addresses, so a `Bcc` header would reach no one it names and show them to every recipient - send a separate message instead. |
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
