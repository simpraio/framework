# HTTP Client

## Configuration

Create `config/httpclient.php` in your project. The example below enables outbound HTTP and shows the supported keys.

```php
return [
    'extensions' => [
        'httpclient' => [
            'enabled'           => true,
            'retries'           => 2,             // max retries on transient failure (safe methods only)
            'retry_delay'       => 0,             // seconds to sleep between retries
            'timeout'           => 10,            // total request timeout in seconds
            'connect_timeout'   => 10,            // connection timeout in seconds
            'verify_tls'        => true,          // enforce TLS certificate verification
            'max_response_bytes' => 10_485_760,  // 10 MB response cap
            'cookie_jar_dir'    => null,          // directory for per-session cookie storage
            'allowed_protocols' => ['http', 'https'],
        ],
    ],
];
```

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `HttpClient::get($url, $options)` | `Response` | Sends a GET request. Throws `HttpClientException` on a non-retriable cURL error or after all retries are exhausted. |
| `HttpClient::post($url, $options)` | `Response` | Sends a POST request. Never retried automatically. |
| `HttpClient::request($url, $options)` | `Response` | Generic request. Pass `'method'` in `$options` to set the HTTP method. Supported option keys: `method`, `headers`, `data`, `raw`, `cookies`, `proxy`, `timeout`, and `connect_timeout`. |

The returned `Response` object exposes:

```
$res->status    // int - HTTP status code
$res->body      // string - raw response body
$res->json      //  - array - decoded JSON, or null if response is not JSON
$res->headers   // array<string, string|list<string>> - lowercased response headers
```

## Example

Fetch a JSON payload and post data to an external API:

```
use extensions\httpclient\HttpClient;
use extensions\httpclient\HttpClientException;

try {
    $res = HttpClient::get('https://api.example.com/users/42', [
        'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);

    if ($res->status === 200 && $res->json !== null) {
        $name = $res->json['name'] " '';
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
