# HTTP Client

## Configuration

Create `config/http-client.php` in your project. The example below enables outbound HTTP and shows the supported keys. Numeric values match the shipped defaults unless noted.

```php
return [
    'extensions' => [
        'http-client' => [
            'enabled'           => true,
            'retries'           => 5,             // max retries on transient failure (safe methods only), within timeout
            'retry_delay'       => 1,             // seconds to sleep between retries
            'timeout'           => 60,            // seconds for the whole call: attempts, retry delays and redirects together
            'connect_timeout'   => 10,            // seconds to connect, per attempt, within timeout
            'verify_tls'        => true,          // enforce TLS certificate verification
            'tls_insecure_acknowledged' => '',     // with verify_tls=false, must be Config::TLS_INSECURE_ACK ('I_ACCEPT_INSECURE_TLS')
            'max_response_bytes' => 10_485_760,  // 10 MB response cap
            'cookie_jar_dir'    => null,          // directory for per-session cookie storage
            'allowed_protocols' => ['http', 'https'],
            'proxy'             => null,          // operator-configured outbound proxy
            'egress' => [
                'enabled' => true,
                'allowlist' => ['api.example.com'],
                'block_private_ips' => true,
            ],
        ],
    ],
];
```

### Proxy and egress (SSRF) options

| Key | Description |
| --- | --- |
| `extensions.http-client.egress.enabled` | Turns the guard on. When on, only `allowlist` hosts are reachable; an empty allowlist denies everything. |
| `extensions.http-client.egress.allowlist` | Exact hostnames, compared case-insensitively. Not patterns, not suffixes. |
| `extensions.http-client.proxy` | Operator-only outbound proxy, never a per-request option. Always passed to cURL (empty when unset) so environment variables cannot route traffic silently, and when set, no host is exempt from it - `NO_PROXY` cannot force a direct connection. A proxy resolves the destination itself, so it cannot be combined with a guard that is enabled and enforcing addresses - boot fails then, provided the client itself is enabled; an extension that does not run enforces nothing, so a disabled client accepts the pair. With the guard disabled, `block_private_ips` does nothing and a proxy is accepted. |
| `extensions.http-client.egress.block_private_ips` | Also requires every address the host resolves to be **globally routable**. This is wider than private and reserved: shared address space (`100.64.0.0/10`, carrier-grade NAT) and the benchmarking and documentation ranges are refused too. Special-purpose ranges that RFC 6890 marks globally reachable — the NAT64 prefix `64:ff9b::/96`, AS112, AMT — remain allowed, because they are routable on the internet. Reachability comes from a static classification of the IANA special-purpose registries, matched longest-prefix-first so a reachable range nested inside an unreachable one is honoured. PHP's `FILTER_FLAG_GLOBAL_RANGE` applies only where that table is silent, since it accepts several ranges IANA marks non-global. A host that resolves to nothing is refused as well - there is no address to check - with the same egress-blocked error as other address-check refusals: see Errors. The validated addresses are then pinned onto the connection, so cURL does not resolve the name again. |

To reach a destination that is deliberately not globally routable - an internal service behind
carrier-grade NAT, for instance - allowlisting the host is not enough, because the address check
runs after the allowlist. Either set `extensions.http-client.egress.block_private_ips` to `false`, which drops that check for
every host and leaves the allowlist as the only control, or route the call through an endpoint that
does have a globally routable address.

The NAT64 prefix is not a blanket exception: an address under `64:ff9b::/96` is allowed only when
its embedded IPv4 address is globally routable too.

A URL the guard refuses throws `HttpClientException('HTTP client egress blocked')` before anything
is sent. A redirect target it refuses throws `HttpClientException('HTTP client redirect egress blocked')`,
on the hop and on any retry of it.

### Checking a URL against the guard

`HttpClient` checks every request itself. To refuse a URL earlier - a webhook address when it is
saved, say - ask the guard the client uses:

```php
use core\config\Config as CoreConfig;
use extensions\http_client\Config;

$egress = CoreConfig::extensionConfig(Config::NAME, Config::class)->egress;  // core\config\Egress

if (!$egress->allows($url)) {
    // refuse the URL
}
```

| Call | Returns | Description |
| --- | --- | --- |
| `$egress->allows($url)` | `bool` | Whether the guard lets `HttpClient` fetch `$url`: the same answer as `resolvedFor($url) !== null`. With `block_private_ips` on, it resolves the host, and that lookup is cached for five seconds. `true` when the guard is disabled. |
| `$egress->resolvedFor($url)` | `list<string>\|null` | `null` refuses. Otherwise the validated addresses a transport must pin, or an empty list when there is nothing to pin: the guard or the address check is disabled, or the host is an IP literal. |

An answer describes the moment it was given: a host can resolve differently later, so a URL
`allows()` accepted can still be refused when it is fetched.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `HttpClient::get($url, $options)` | `Response` | Defaults to GET. Throws `HttpClientException` on a non-retriable cURL error, after all retries are exhausted, or once `timeout` is spent. |
| `HttpClient::post($url, $options)` | `Response` | Defaults to POST. A POST transfer itself is never retried automatically. |
| `HttpClient::request($url, $options)` | `Response` | Generic request. Pass `'method'` in `$options` to set the HTTP method. Supported option keys: `method`, `headers`, `data`, `raw`, `cookies`, `timeout`, `connect_timeout`, and `follow_redirects` (default `true`; set `false` to return the original redirect response). |

The shorthand methods set defaults, not forced verbs: an explicit `method` option overrides them.
Prefer `request()` when choosing the method yourself. Retries use the actual hop's method and body;
a bodiless GET reached after a POST redirect may be retried without repeating the original POST.

A URL must be an absolute `http` or `https` URL that `core\http\Url::parse()` accepts, or the
call throws `HttpClientException('HTTP client URL rejected')` before anything is sent. That
refuses credentials in the URL - put them in an `Authorization` header instead, as in the
example below - and any URL containing whitespace, a control byte or a backslash, or with a
percent-encoded or non-ASCII host; write an internationalised host in punycode (`xn--bcher-kva.example`), the
name cURL would connect to. The egress guard reads the URL with the same parser, so a URL it
approves is the URL cURL connects to. A redirect to a refused URL fails the same way, with
`HTTP client redirect URL rejected`, whether or not the egress guard is on.

`method` is one of `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` or `OPTIONS`, in any case -
the list is `core\http\Request::METHODS`. In `request()`, leaving it out, or passing `null` or an empty
string, means `POST` when `data` is set and `GET` when it is not. The shorthand defaults above apply
when their `method` option is omitted; explicitly passing `null` or `''` infers it from `data` too.
Any other value throws
`HttpClientException('HTTP client method rejected')` before anything is sent.

A `POST`, `PUT` or `PATCH` without `data` is sent with `Content-Length: 0`, never chunked, so a
server or gateway that refuses chunked requests accepts it. `GET`, `HEAD`, `DELETE` and `OPTIONS`
without `data` declare no body. `raw` only changes how `data` is sent, so it makes no difference to
a request without `data`: a raw `GET` without it carries no body and is retried like any `GET`.

The returned `Response` object exposes:

```
$res->status    // int - HTTP status code
$res->body      // string - raw response body
$res->json      // mixed - decoded JSON, or null if response is not JSON
$res->headers   // array<string, string|list<string>> - lowercased response headers
```

All four are read-only. `$json` is decoded from `$body` when it is first read, and the result is
kept for later reads. A body is decoded only when its `Content-Type` contains `json`; invalid
JSON reads as `null`. Decoded, a body can take several times its size in memory, so a response
whose `$json` is never read costs only its body.

### Request headers

`headers` maps a header name to a scalar value. Apart from the reserved names below, which throw
whatever their value, an entry whose name is not an RFC 9110 token, whose value holds a CR, LF or NUL,
or whose value is not a scalar is dropped without an error.

cURL frames the request, so a caller cannot name the headers that do. `Host`, `Content-Length`,
`Transfer-Encoding`, `Connection`, `TE`, `Upgrade`, `Expect` and every `Proxy-*` header, in any
letter case, make the call throw `HttpClientException` naming the header, before anything is sent. A
`Host` of the caller's would send the request to another virtual host on the address the egress check
approved for the URL's host; a length or encoding of the caller's would make the body disagree with
the framing cURL computes; a proxy is the operator's setting, never a request's. A header such as
`X-Host` or `Content-Language` is not affected, and the URL decides the `Host` that is sent.

The client never sends `Expect: 100-continue`: on every attempt, retry and redirect hop it hands cURL
an empty `Expect:` line, so a large body is sent at once instead of waiting about a second for a
`100 Continue` that a server may never send. `'Expect' => ''` is therefore not needed, and is refused
like any other `Expect`.

### Timeouts

`timeout` bounds the whole call to `get()`, `post()` or `request()`: every attempt, every
`retry_delay` pause and every redirect hop share it, measured on a monotonic clock. Each attempt
is given what is left of it, so neither a retry nor a redirect starts a fresh budget. A retry is
made only while more than `retry_delay` seconds remain. When the time is spent the call throws
`HttpClientException` with `$curlError` `28` (`CURLE_OPERATION_TIMEDOUT`): with cURL's message
when an attempt runs out, or `HTTP client timeout exceeded` when no time is left to start one.
`connect_timeout` limits connecting within each attempt and never extends the call. Resolving a
host name for the egress check is not interrupted by the timeout, but it counts against it: an
attempt gets what the lookup left, and a lookup that spends it all ends the call with `28`
as soon as it returns, before anything is sent. The first request, each redirect hop and each
retry resolve their host at most once, and connect to the addresses that lookup validated. A
hop or retry with no time left is not resolved at all: it ends with `28`.

The per-request `timeout` and `connect_timeout` options replace the configured values for that
call, with the same meaning.

### Redirects

With `follow_redirects` on, a `301`, `302`, `303`, `307` or `308` response is followed for up
to 5 hops; a redirect after the fifth is returned as the response. A `303`, and a `301` or `302` answering a
`POST`, is followed as a `GET` without the body. Each target is checked as the first URL was,
and egress is checked again for every hop.

A redirect from `https` to `http` is refused with
`HttpClientException('HTTP client redirect downgrade rejected')` before anything is sent to the
target, whether or not the egress guard is on. Follow such a redirect yourself, with
`follow_redirects` set to `false`, if it is really wanted.

A hop to another origin - a different scheme, host or port, with `80` and `443` standing for a
port the URL leaves out - keeps only these caller headers, matched case-insensitively: `Accept`,
`Accept-Encoding`, `Accept-Language`, `User-Agent`, `Cache-Control` and `Pragma`. Every other
header stays behind - `Authorization`, `Cookie`, an API key in `X-Api-Key` and any other custom
header - and so does the `cookies` jar. A request with `data` loses it too and goes to the new
origin as a `GET`, whatever the status, a `307` or `308` included: the body was written for the
first origin. When a header or a body really must follow a redirect to another host, set
`follow_redirects` to `false` and decide about the `Location` yourself. A hop within the same
origin keeps every header, the cookie jar and the body, and a `307` or `308` keeps the method.

### Cookie jars

The `cookies` option names a file cURL reads cookies from before the request and writes them back to
afterwards. It needs `cookie_jar_dir`, and the file must be a regular file inside that directory or a
subdirectory of it; one that does not exist yet is created. The path is resolved before it is used and
cURL is given the resolved file, so what was checked is what is opened. Each of these throws
`HttpClientException` before anything is sent, with a fixed message that names no path:

- a path that resolves outside `cookie_jar_dir`, or whose directory does not exist;
- a symbolic link, wherever it points, a dangling one included;
- a directory, which includes a path ending in `.` or `..`.

The checks run before every transfer, retries and redirect hops that keep the jar included, without
relying on what PHP cached about the path earlier: under PHP-FPM those caches outlive the request. They
cannot stop another process from replacing the file after they pass, so make `cookie_jar_dir` writable
only by the account the application runs as, and never a directory that uploads or other accounts can
write to.

### Errors

`HttpClientException::$url` holds a label of the URL the failure concerns - scheme, host and a
port the URL named, never its path, query, fragment or credentials - or `[unparseable-url]` for
a URL that cannot be parsed. That holds for every failure, one at a redirect target the server
chose included. `HttpClientException::$curlError` is the cURL error code, or `0` when the
request was refused before or without a transport error.

A host the egress guard refuses - off the allowlist, resolving to no address, or to an address that is
not globally routable - fails with `HTTP client egress blocked` (`HTTP client redirect egress blocked`
for a redirect target) and `0`, and is not retried.

`HttpClientException::$sent` is `false` only when nothing of the request was written: it was refused
before the first transfer (URL, method, egress, cookie file, no time left), or cURL could not resolve
the host or the proxy, found the host refusing or unreachable, or failed the TLS handshake or the server, client or CA certificate check. Otherwise it is `true`, a timeout
while connecting included: the request may have reached the server, and after a redirect was followed
it did. **Send a `POST`, or any request that is not safe to repeat,
again only when `$sent` is `false`.** The client retries by itself only a `GET`, `HEAD` or `OPTIONS`
without a body.

## Example

Fetch a JSON payload and post data to an external API:

```
use extensions\http_client\HttpClient;
use extensions\http_client\HttpClientException;

try {
    $res = HttpClient::get('https://api.example.com/users/42', [
        'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);

    if ($res->status === 200 && $res->json !== null) {
        $name = $res->json['name'] ?? '';
    }
} catch (HttpClientException $e) {
    // handle connection or timeout error
}
```

```
// POST JSON body manually
$res = HttpClient::post('https://api.example.com/orders', [
    'raw'     => true,
    'data'    => json_encode(['product_id' => 7, 'qty' => 2], JSON_THROW_ON_ERROR),
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
    ],
]);
```

```
// Send the order again only when it provably never left
try {
    $res = HttpClient::post('https://api.example.com/orders', $options);
} catch (HttpClientException $e) {
    if (!$e->sent) {
        // safe to try again
    }
}
```
