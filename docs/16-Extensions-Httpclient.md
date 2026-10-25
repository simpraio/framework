# HTTP Client

## Configuration

Create `config/http-client.php` in your project. The example below enables outbound HTTP and shows the supported keys. Numeric values match the shipped defaults unless noted.

```php
return [
    'extensions' => [
        'http-client' => [
            'enabled'           => true,
            'retries'           => 5,             // max retries on transient failure (safe methods only)
            'retry_delay'       => 1,             // seconds to sleep between retries
            'timeout'           => 60,            // total request timeout in seconds
            'connect_timeout'   => 10,            // connection timeout in seconds
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
| `HttpClient::get($url, $options)` | `Response` | Sends a GET request. Throws `HttpClientException` on a non-retriable cURL error or after all retries are exhausted. |
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

The returned `Response` object exposes:

```
$res->status    // int - HTTP status code
$res->body      // string - raw response body
$res->json      // mixed - decoded JSON, or null if response is not JSON
$res->headers   // array<string, string|list<string>> - lowercased response headers
```

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
