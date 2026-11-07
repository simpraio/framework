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
| `extensions.http-client.egress.block_private_ips` | Also requires every address the host resolves to be **globally routable**. This is wider than private and reserved: shared address space (`100.64.0.0/10`, carrier-grade NAT) and the benchmarking and documentation ranges are refused too. Special-purpose ranges that RFC 6890 marks globally reachable — the NAT64 prefix `64:ff9b::/96`, AS112, AMT — remain allowed, because they are routable on the internet. Reachability comes from a static classification of the IANA special-purpose registries, matched longest-prefix-first so a reachable range nested inside an unreachable one is honoured. PHP's `FILTER_FLAG_GLOBAL_RANGE` applies only where that table is silent, since it accepts several ranges IANA marks non-global. A host that resolves to nothing is refused as well - there is no address to check. The validated addresses are then pinned onto the connection, so cURL does not resolve the name again. |

To reach a destination that is deliberately not globally routable - an internal service behind
carrier-grade NAT, for instance - allowlisting the host is not enough, because the address check
runs after the allowlist. Either set `extensions.http-client.egress.block_private_ips` to `false`, which drops that check for
every host and leaves the allowlist as the only control, or route the call through an endpoint that
does have a globally routable address.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `HttpClient::get($url, $options)` | `Response` | Sends a GET request. Throws `HttpClientException` on a non-retriable cURL error, after all retries are exhausted, or once `timeout` is spent. |
| `HttpClient::post($url, $options)` | `Response` | Sends a POST request. Never retried automatically. |
| `HttpClient::request($url, $options)` | `Response` | Generic request. Pass `'method'` in `$options` to set the HTTP method. Supported option keys: `method`, `headers`, `data`, `raw`, `cookies`, `timeout`, `connect_timeout`, and `follow_redirects` (default `true`; set `false` to return the original redirect response). |

A URL must be an absolute `http` or `https` URL that `core\http\Url::parse()` accepts, or the
call throws `HttpClientException('HTTP client URL rejected')` before anything is sent. That
refuses credentials in the URL - put them in an `Authorization` header instead, as in the
example below - and any URL containing whitespace, a control byte or a backslash, or with a
percent-encoded host. The egress guard reads the URL with the same parser, so a URL it
approves is the URL cURL connects to. A redirect to a refused URL fails the same way, with
`HTTP client redirect URL rejected`, whether or not the egress guard is on.

`method` is one of `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` or `OPTIONS`, in any case -
the list is `core\http\Request::METHODS`. Leaving it out, or passing `null` or an empty
string, means `POST` when `data` is set and `GET` when it is not. Any other value throws
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

### Timeouts

`timeout` bounds the whole call to `get()`, `post()` or `request()`: every attempt, every
`retry_delay` pause and every redirect hop share it, measured on a monotonic clock. Each attempt
is given what is left of it, so neither a retry nor a redirect starts a fresh budget. A retry is
made only while more than `retry_delay` seconds remain. When the time is spent the call throws
`HttpClientException` with `$curlError` `28` (`CURLE_OPERATION_TIMEDOUT`): with cURL's message
when an attempt runs out, or `HTTP client timeout exceeded` when no time is left to start one.
`connect_timeout` limits connecting within each attempt and never extends the call. Resolving a
host name for the egress check is not interrupted by the timeout.

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

### Errors

`HttpClientException::$url` holds a label of the URL the failure concerns - scheme, host and a
port the URL named, never its path, query, fragment or credentials - or `[unparseable-url]` for
a URL that cannot be parsed. That holds for every failure, one at a redirect target the server
chose included. `HttpClientException::$curlError` is the cURL error code, or `0` when the
request was refused before or without a transport error.

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
