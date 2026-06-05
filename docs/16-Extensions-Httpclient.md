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
            'tls_insecure_acknowledged' => '',     // required only when verify_tls=false
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

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `HttpClient::get($url, $options)` | `Response` | Sends a GET request. Throws `HttpClientException` on a non-retriable cURL error or after all retries are exhausted. |
| `HttpClient::post($url, $options)` | `Response` | Sends a POST request. Never retried automatically. |
| `HttpClient::request($url, $options)` | `Response` | Generic request. Pass `'method'` in `$options` to set the HTTP method. Supported option keys: `method`, `headers`, `data`, `raw`, `cookies`, `timeout`, and `connect_timeout`. |

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
