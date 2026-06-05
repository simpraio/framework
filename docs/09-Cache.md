# Cache

`core\cache\Cache` is a two-tier static facade: a per-request in-memory store backed by APCu for cross-request persistence. Reads check memory first, then APCu (promoting hits to memory). TTL applies to APCu only; memory entries live for the duration of the current request.

APCu is required for cache entries to persist across requests. When APCu is unavailable the facade degrades gracefully: memory-only caching still works for the current request, and `once()` falls back to a file-mtime sentinel.

## Basic usage

```php
// Store with an optional TTL in seconds (0 = no expiry)
Cache::set('config.limits', $limits, 3600);

// Read with an optional default
$limits = Cache::get('config.limits', []);

// Check existence
if (Cache::has('config.limits')) { ... }

// Delete a specific key
Cache::delete('config.limits');

// Delete all keys sharing a prefix
Cache::deletePrefix('config.');
```

## Get or compute

```php
// Fetch from cache or run the callback once, store, and return the result
$rows = Cache::remember('products.featured', function (): array {
    return Db::select('SELECT * FROM products WHERE featured = 1');
}, ttl: 300);
```

Under concurrent load, `remember()` uses an APCu atomic-add lock so only one process computes on a cold miss. Other processes wait 5 ms and re-check before falling back to computing themselves.

## Run once per TTL

```php
// Callback runs at most once per $ttl seconds across all processes
Cache::once('maintenance.purge', function (): void {
    Db::execute('DELETE FROM sessions WHERE expires_at < ?', [time()]);
}, ttl: 86400);
```

`once()` returns `true` if the callback ran, `false` if it was skipped (already ran within the TTL). The error-log extension uses this pattern to throttle its purge queries.

## Atomic counter

```php
// Atomically increment a counter (creates the key if absent)
$hits = Cache::inc('page.hits.' . $routeId, step: 1, ttl: 3600);
```

`inc()` invalidates the in-memory tier so the updated value is visible on the next read within the same request. Returns `false` when APCu is unavailable.

## Conditional store

```php
// Store only if the key does not already exist (cross-process atomic via APCu add)
$acquired = Cache::add('lock.invoice.' . $id, 1, ttl: 30);
if ($acquired) {
    // exclusive section
}
```

`add()` only touches APCu and does not write to the in-memory tier, since its semantics are only meaningful in a cross-process context.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Cache::get($key, $default)` | `mixed` | Read from memory, then APCu. Returns `$default` on miss. |
| `Cache::has($key)` | `bool` | Return `true` if the key exists in either tier. |
| `Cache::set($key, $value, $ttl)` | `bool` | Write to both tiers. `$ttl = 0` means no APCu expiry. |
| `Cache::add($key, $value, $ttl)` | `bool` | Write to APCu only if the key is absent. Returns `true` on success. |
| `Cache::inc($key, $step, $ttl)` | `int\|false` | Atomically increment a counter in APCu. Returns the new value or `false`. |
| `Cache::delete($key)` | `void` | Remove from both tiers. |
| `Cache::deletePrefix($prefix)` | `void` | Remove all keys that start with `$prefix` from both tiers. |
| `Cache::remember($key, $callback, $ttl)` | `mixed` | Return cached value or compute, store, and return it. |
| `Cache::once($key, $callback, $ttl, $waitSeconds)` | `bool` | Run `$callback` at most once per `$ttl` seconds. Returns `true` if it ran. |
