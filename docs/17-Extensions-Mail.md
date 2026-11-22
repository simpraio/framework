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
            'max_recipients'    => 100,         // 1 to 1000
            'max_message_bytes' => 26_214_400,  // 25 MiB, 1024 to 268435456
            'smtp' => [
                'host'       => '',
                'port'       => 587,
                'encryption' => 'tls',    // 'tls', 'ssl', or 'none'
                'auth'       => true,
                'username'   => '',
                'password'   => '',
                'timeout'    => 30,
                'ehlo_host'  => '',       // empty: the machine name when valid, else localhost
            ],
        ],
    ],
];
```

The `native` transport uses PHP's built-in `mail()` function. Use `smtp` for all production deployments - it gives you explicit control over authentication, encryption, and the sending server.

### SMTP transport

- `encryption` is `tls` (STARTTLS on the configured port, the default), `ssl` (TLS from the first byte, usually port 465) or `none`, matched case-insensitively. Any other value - a typo such as `sll` - is refused when the message is sent, with `Config: 'extensions.mail.smtp.encryption' must be one of none, ssl, tls`, rather than falling back to a mode you did not choose. Both TLS modes - `ssl` from the first byte and `tls` after STARTTLS - accept TLS 1.2 and 1.3 only, never SSL or TLS 1.0 or 1.1, whatever the PHP and OpenSSL in use would otherwise negotiate, and verify the server's certificate and its host name.
- `ehlo_host` is the name the client announces in `EHLO`, and in `HELO` when the relay refuses `EHLO`: a host name or a bracketed address literal such as `[192.0.2.1]` for IPv4 or `[IPv6:2001:db8::1]` for IPv6. The IPv6 tag is case-insensitive and must match the address family: `[::1]` and `[IPv6:192.0.2.1]` are refused, and so is an address without brackets such as `192.0.2.1`, which RFC 5321 accepts only as a literal. Anything else, an underscore or a space in it included, refuses the configuration with `Config: 'extensions.mail.smtp.ehlo_host' must be a hostname or a bracketed address literal.` Empty, the default, announces the machine name when that is a valid host name - dot-separated labels of letters, digits and inner hyphens, at most 63 characters each and 253 in all - and `localhost` when it is not - a name of digits and dots only included - so a machine named `build_01` does not fail every send. Set it to the fully qualified name when the relay requires one.
- `timeout` (seconds) bounds the connection attempt and each server reply as a whole, not each read: a server that trickles a reply is cut off at the timeout with `SMTP_TIMEOUT`. A reply larger than 64 KiB is refused with `SMTP_RESPONSE_TOO_LARGE`.
- A reply must reach the end of its final line. EOF during a line, or after a multiline continuation without a final line, is `SMTP_INVALID_RESPONSE`; after the message data is sent this becomes `SMTP_DELIVERY_UNKNOWN: SMTP_INVALID_RESPONSE`, not successful delivery or a definite rejection. All the lines of a multiline reply must carry its one code - `250-` on each but the last, `250 ` or a bare `250` on the last. A reply that changes code, such as `250-queued` followed by `550 rejected`, says nothing certain about the verdict and is `SMTP_INVALID_RESPONSE` too, with the same `SMTP_DELIVERY_UNKNOWN` wrapper after the message data.
- A failure throws a `RuntimeException` carrying an `SMTP_*` code, such as `SMTP_CONNECT_FAILED`, `SMTP_TLS_FAILED`, `SMTP_EMPTY_RESPONSE` or `SMTP_WRITE_FAILED`, or `SMTP: <reply>` when the server refuses a command. Connection and handshake failures carry their code under the framework's error handler too, not an `ErrorException`.
- STARTTLS failures include a reason after `SMTP_TLS_FAILED: `: the first TLS warning, bounded to 512 bytes with control characters replaced by spaces, or a fallback when PHP supplies none. Unread plaintext after the STARTTLS reply is refused before the handshake and named separately. Match the code prefix rather than the whole diagnostic, whose wording depends on PHP and OpenSSL.
- Connection failures include a reason after `SMTP_CONNECT_FAILED: ` under the same bounds: the operating system's error message, or, when there is none - an `ssl` handshake or certificate failure, for example - the first connection warning, which carries the OpenSSL diagnostic. With neither, the code stands alone as `SMTP_CONNECT_FAILED`.
- The server's `250` to the message data is the point of delivery. Any other `2xx` reply there counts as accepted too: RFC 5321 requires `250`, but reporting another success code as a failure would make a retrying caller send the message twice. What follows it - `QUIT`, and closing the connection - is best effort: a server that hangs up instead of answering `QUIT` does not make `Mail::send()` throw, so a caller that retries on failure does not send the message twice.
- Once the message data and its terminating `.` have been sent, the server may have committed the message even if its reply never arrives. A connection lost, a timeout, an unreadable reply, or a reply that neither accepts nor refuses (`354`, `1xx`) at that point throws `SMTP_DELIVERY_UNKNOWN: <cause>` (for example `SMTP_DELIVERY_UNKNOWN: SMTP_TIMEOUT`), with the original failure as the exception's `getPrevious()`. Do not retry it automatically: SMTP has no idempotency, so a retry can deliver the message twice. Record it for follow-up and check out of band - the relay's logs or the recipient's mailbox - before sending again anything that must not arrive twice. A `4xx` or `5xx` reply refusing the data (`SMTP: 451 ...`, `SMTP: 554 ...`) is a definite failure, and every failure before the data is sent keeps its own code; neither delivered the message.

### Limits

- `max_recipients` (1 to 1000, default 100) is the most addresses one message may be sent to. More refuses `Mail::send()` with `InvalidArgumentException('TOO_MANY_RECIPIENTS')`.
- `max_message_bytes` (1024 to 268435456, default 25 MiB) caps what the message carries: its text, its HTML and its attachments after base64 encoding, with line breaks as they are sent (CRLF). Headers and part headers are not counted. More refuses `Mail::send()` with `InvalidArgumentException('MESSAGE_TOO_LARGE')`, and so does a number of attachments that no message of that size could frame, more than one per 128 bytes of the limit.
- Both are decided from lengths alone, before MIME encoding and before any connection is made, so a refused message is not encoded or sent. Configuration is lazy: a value outside its range throws when mail configuration is first read, for example by `Mail::message()` or `Mail::send()`, even before the enabled check, as `Config: 'extensions.mail.max_recipients' must be 1 to 1000, got 0.` Mail has no `Boot.php`, so an unrelated request does not validate these limits.
- Building and sending a message at `max_message_bytes` takes roughly four times its encoded size in memory (about 100 MiB at the default, measured on PHP 8.5): keep PHP's `memory_limit` above that, or lower the limit. A relay applies a size limit of its own, and counts the headers.

## Public API

Build a message with the fluent `Message` API, then send it:

| Call | Returns | Description |
| --- | --- | --- |
| `Mail::message()` | `Message` | Creates a new blank message. |
| `Mail::send($message)` | `void` | Builds and delivers the message via the configured transport. Throws `RuntimeException` if the from address or a required runtime dependency is missing, and `InvalidArgumentException` for a message over a limit (`TOO_MANY_RECIPIENTS`, `MESSAGE_TOO_LARGE`). |
| `$msg->to($address)` | `Message` | Sets recipients. Accepts a single address, a comma-separated string, or an array. RFC 5322 name+address syntax (`Name <email>`) is supported, and the address is the angle-addr that ends the item, so in `"Name <a@x.test>" <b@x.test>` it is `b@x.test`. A comma in a quoted display name does not separate items, and a backslash there escapes the next character. Comments are not read: `Name <a@x.test> (work)` is not a valid item. Empty items are skipped; any other item that is not a valid address - an address holding a control character, a blank angle-addr, or a quote left open among them - refuses the whole call with `InvalidArgumentException('INVALID_RECIPIENT')` and leaves the earlier recipients in place, rather than sending to the rest. So does an item where a comma was missed and the earlier address is outside quotes: an address or angle-addr before the angle-addr, as in `jan@x.test Eva <eva@x.test>`, or a quote after an address. Between display names holding stray quotes, as in `a" jan@x.test b" <eva@x.test>`, a missed comma reads as part of one display name and the item goes to `eva@x.test` alone: it has the same form as a display name quoting an address among other words, such as `Help "jan@x.test desk" <eva@x.test>`. An address used as a display name must be quoted: `"jan@x.test" <jan@x.test>`. In a string, an item whose quoted strings hold a comma and an `@` between them refuses the call as well, since that is what stray quotes in display names make of the recipients between them. An array item is one address: a comma outside quotes in it refuses the call. Display names are not sent - the `To` header lists the addresses alone - so when they come from users, pass an array of bare addresses. Input whose items are all blank throws `InvalidArgumentException('NO_VALID_RECIPIENT')`. A string, or an array item, longer than 1 MiB (1,048,576 bytes) refuses the call before it is read. An address is valid by the rule of `Validator::email()`, the same for every address the mail layer sends with - a recipient, the envelope sender, the `To` and `From` lines: no control byte, space, quote, backslash or angle bracket. A quoted local part such as `"jane doe"@example.test` is therefore refused, as `INVALID_RECIPIENT` here and `INVALID_ENVELOPE_RECIPIENT` for a recipient written to `$message->recipients` directly. Generated `To` lines are folded between addresses. |
| `$msg->subject($text)` | `Message` | Sets the subject. Non-ASCII or oversized text is encoded into bounded RFC 2047 encoded-words; short ASCII text stays unchanged. Invalid UTF-8, a line break, or any other control character except tab is refused with `InvalidArgumentException('INVALID_MAIL_HEADER_VALUE')` rather than encoded or flattened. This setter only trims spaces and tabs - the refusal happens when the message is built, so catch it around `Mail::send()` or `Composer::build()`, not around this call. |
| `$msg->html($html)` | `Message` | Sets the HTML body. A plain-text fallback is generated automatically unless `->text()` is called explicitly: tags and comments removed, entities decoded, `<style>`, `<script>` and `<title>` left out (each up to its first closing tag: a script holding `<!--<script>` may show text after its first `</script>` that a browser would hide), each block element (`p`, `div`, `li`, `tr`, headings, `br` ...) on its own line with white space collapsed, and table cells separated by a space. A link to an `http:`, `https:` or `mailto:` address is written as `text (url)`, or the address alone when it is the text or the link has none (a `mailto:` link whose text is the bare address shows both, as `a@x.test (mailto:a@x.test)`); spaces, controls, `"`, `(`, `)`, `<`, `>`, backtick and non-ASCII in the address are percent-encoded, so nothing in it can pose as a second address. An address that still holds a character reference after decoding, one PHP does not decode such as `&#1;`, is not shown at all, since its `#` would make the host after it read as a fragment; the link text still is. An invalid UTF-8 sequence is U+FFFD. A quote opens an attribute value only straight after `=`, so `class=a'b` does not swallow the text after the tag as `strip_tags()` did. The time taken grows in proportion to the body. |
| `$msg->text($text)` | `Message` | Sets an explicit plain-text body, overriding the auto-generated fallback. With both bodies the message is `multipart/alternative`, text first and HTML last; with only one of them - text and no `->html()`, or HTML with an explicit `->text('')` - it is that single part, because a client shows the last alternative it can render and an empty one would display a blank message. Attachments wrap whichever of these in `multipart/mixed`. |
| `$msg->from($email, $name)` | `Message` | Overrides the config-level from address for this message only. The address is validated by the same rule as a recipient's; an invalid one falls back to the configured address. `$name` is safely quoted or encoded as a display name and never affects the SMTP envelope sender. |
| `$msg->messageId($id)` | `Message` | Sets the `Message-ID` header to `$id`, with or without angle brackets, instead of a random one built from the sender's domain. Without it a retry of a send carries a new id, so a receiver cannot tell it from a second message, and `Mail::send()` returns nothing to learn the generated one from. The id is `id@host`: letters, digits and the RFC 5322 atom characters, dot-separated, then a host name, at most 255 bytes. A key with a colon in it, such as a queue operation key, is not an id: use `hash('sha256', $key) . '@mail.example.test'`. Anything else throws `InvalidArgumentException('INVALID_MESSAGE_ID')`. The `messageId` property can be read but is set only by this method. A `Message-ID` custom header is still ignored. |
| `$msg->attach($name, $data)` | `Message` | Attaches a file. `$data` is the raw binary content. MIME type is detected automatically, and long UTF-8 filenames use bounded RFC 2231 continuations. An empty name, or one longer than 255 bytes, throws `InvalidArgumentException('INVALID_ATTACHMENT')`; control characters in a name become `_`. `Message::$attachments` is public, and an entry `attach()` did not make is checked again when the message is built: a non-array entry (objects included), a name, percent-encoded name or media type holding anything `attach()` would have cleaned, a name over 255 bytes, or a value of the wrong type throws `InvalidArgumentException('INVALID_ATTACHMENT')` before size calculation accesses it and before anything is encoded or sent. |
| `$msg->header($name, $value)` | `Message` | Adds a custom header. Silently ignored are: reserved headers (From, To, Cc, Bcc, Subject, Date, Message-ID, MIME-Version, Content-Type, Content-Transfer-Encoding, Content-Disposition, Sender, Return-Path, Received: the message and its transport write those), empty values, names or values containing CR/LF, names that are not a valid RFC 5322 field name, that start with a dot (an SMTP hop strips one leading dot from a line, so `.From` would arrive as `From`), that are all digits or are canonical negative integers such as `-1` (PHP converts canonical integer names into integer array keys), values carrying anything but printable ASCII and tab - a control character, or raw Unicode, which would need SMTPUTF8 this client does not negotiate - and headers whose rendered line would exceed 998 octets. Non-integer string keys such as `-1a` and `-01` remain valid. A direct `Envelope` rejects unsupported names at construction as `INVALID_ENVELOPE_HEADER_NAME`. Pass the ASCII form the header's own grammar calls for. An RFC 2047 encoded-word (`=?UTF-8?B?...?=`) is valid only where that grammar admits one - an unstructured value, a comment, or a display-name phrase - and never inside an address, token or quoted string (RFC 2047 section 5), so encoding a whole `Reply-To` value yields a field with no mailbox in it. The framework does not encode custom fields for you, because only the caller knows the field's grammar; subjects, display names and attachment filenames it does own, and encodes. A custom header is dropped rather than refused, so one bad value never stops the message. There is no Cc or Bcc: SMTP delivers only to the `->to()` addresses, so a `Bcc` header would reach no one it names and show them to every recipient - send a separate message instead. |
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
