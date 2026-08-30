# Changelog

All notable changes to this project will be documented in this file.

## [5.0.0] - 2026-08-30

### Added

- `session.cache_limiter` (default `nocache`), so the cache headers PHP stamps on any response that started a session are a stated setting rather than whatever `php.ini` happened to hold. Supported values are `nocache`, `private`, `private_no_expire`, and `''`; `public` is rejected because it would make every session-backed response eligible for storage by shared caches. Set `''` only when the application sets a safe `Cache-Control` header on every such response.
- `Session::hasCookie()`, which reports whether the request presented a non-empty session cookie without starting one. It deliberately does not claim that the identifier maps to stored session data.
- `tools/schema/migration-utc-storage.sql`, which converts the framework tables' locally stored instant columns to UTC for projects adopting the change below. Run it only in a maintenance window, after setting the previous storage timezone and verifying named-zone support when needed. A value whose conversion cannot be resolved - an unloaded named zone, or a stored date `CONVERT_TZ` cannot read - is left as it stands rather than written as `NULL`, and the script ends by reporting how many rows each table skipped. Values in a DST transition window cannot be detected that way, because `CONVERT_TZ` resolves both the repeated and the nonexistent hour to a deterministic instant; the script instead reads the zone's transition history and, before converting anything, records those rows and their original local values in a `simpra_dst_review` table, because they are indistinguishable once converted. It also records itself in a `simpra_migration` table, so a second run cannot shift the data twice.
- `tools/verify-vendored.php`, which compares a project's flattened framework copy against this checkout using each repository's Git filters.
- A shared, discovered test layout under `tests/core/`, `tests/extensions/`, and `tests/integration/`.
- `Format::escape()` takes a `trim` argument, defaulting to the trimming it already applied. Template token escaping and the error-page fallback now go through it instead of calling `htmlspecialchars()` directly, so the framework escapes output in one place. The flags and encoding stay fixed at `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8 so no caller can weaken them.

### Changed

- **Breaking:** MySQL connection sessions are pinned to `+00:00` instead of following `project.timezone`. One setting previously answered two unrelated questions - what humans read, and how instants are stored - so `DEFAULT CURRENT_TIMESTAMP(6)` columns held local wall clock. **Stop every database writer, deploy 5.0.0, convert existing instant columns with `tools/schema/migration-utc-storage.sql`, and only then resume writes.** Any other order can mix local and UTC rows. `Database::fromArray()` now takes only the raw config array instead of a second project-timezone argument, and the database DTO no longer exposes a timezone property; database storage timezone is deliberately not configurable.
- The MySQL init-command PDO option is reserved by the framework: it carries the UTC pin, and a connection built with an application value for it is refused rather than silently overwritten. Run any additional session setup explicitly after connecting. Note that `database.options` only ever reaches the connection with string keys, so a PDO attribute constant set there was already being dropped before this release; that is a separate gap and is not fixed here.
- `Auth::login()` writes `last_login_at` with `UTC_TIMESTAMP(6)`, matching the new storage contract. The v5 migration converts existing values because v4 wrote them through the project-timezone formatter.
- The error-log extension no longer stamps `created_at` from PHP. It used the display formatter while purging compared against the database clock, so the two agreed only while both were in the same zone; the column's own default now writes it.
- Error pages send `robots: noindex, nofollow`, so a crawled 500 cannot compete in search results with the page that was meant to load, and declare `color-scheme: light dark` in the markup as well as in `error.css` - the stylesheet failing to load is exactly the case this page must survive.
- The template-free fallback error page uses the configured project name and links home instead of hardcoding the Simpra wordmark, so products do not need to patch vendored core for fallback branding.
- Generic suites are discovered instead of listed, can run from either the framework checkout or a flattened project, and report unmet database prerequisites as skips instead of false passes. Benchmarks moved to `tools/bench/`, the cache helper moved to `tools/clear-cache.php`, and the former top-level battery is now `tests/integration/battery.php`.
- The deployment security probe accepts `--csrf-path`, never follows redirects, and reports an off-origin redirect for a missing route as a warning rather than mistaking it for proof that the route was safely rejected.

### Fixed

- Routes that map to neither a controller nor a template now return `404` before route-scoped extension hooks run. This prevents deny-by-default authentication from redirecting nonexistent URLs to sign-in. Because the application rate limiter is a route hook, missing routes no longer reach it; see UPGRADING for the behavior change.
- Anonymous visitors are no longer issued a session by mistake. Session start is lazy by design, but the auth extension's contributor hook runs on every rendered page and read the session — and reading one starts it — so every first-time visitor and every crawler was handed a cookie and a session file, and the page went out with `Cache-Control: no-store`, uncacheable by any shared cache. The hook now resolves a request carrying no session cookie as a guest without touching the session; logging in, flash messages and CSRF tokens still start one on demand.
- Removed the error page's dead SQL error-detail scaffolding. `isSQL` was hardwired to `false` and the `SQL_QUERY`/`SQL_BINDS` tokens to `''` from the day they were added, so the error page never printed any SQL and the block never rendered — it was never a working feature. If your own `templates/error.html` still contains the `{isSQL}...{-isSQL}` block, delete it when upgrading: `Template::blocks()` only rewrites the names it is given, so the leftover markers would otherwise render literally on the error page.

## [4.0.0] - 2026-07-26

### Added

- `401` and `403` responses are logged at `warning` with the request method, route path and `REMOTE_ADDR`. Other 4xx responses stay unlogged so a `404` sweep cannot flood the log. The query string is omitted because it may carry credentials or personal data, and the username is not recorded because the denial is logged by the core error handler, which has no notion of identity.
- `Cast::oneOf()` for configuration keys that are effectively enums: it matches case-insensitively, returns the canonical spelling, and rejects anything else at boot.

### Changed

- **Breaking:** `log.level` and `session.same_site` are now validated against their accepted values (`debug`, `info`, `warning`, `error` and `Lax`, `Strict`, `None`) instead of accepting any string, so **a deployment carrying an invalid value no longer starts** — it fails at boot with a message naming the key. Check both keys before upgrading. Values are matched case-insensitively, so existing spellings such as `lax` keep working. Both failed silently before: a misspelled `log.level` made every log call raise "Undefined array key", which the error handler promoted to a 500, while an unrecognised `same_site` was emitted verbatim and then ignored by the browser, quietly weakening the cookie without otherwise disturbing the application.
- Session revalidation also refreshes `group_id` from the database, so a group change takes effect within `revalidate_interval` instead of only at the user's next login.
- **Breaking:** `Cast::bool()` rejects a blank or whitespace-only string instead of reading it as `false`, so a boolean config key set to `''` now fails at boot rather than silently resolving to `false`. `filter_var()` maps `''` to `false` rather than to a failure, so such a value quietly disabled whatever the key controlled while `Cast::int()` and `Cast::string()` rejected the same input.
- **Breaking:** error-page internals moved into a `core\error` subsystem: `core\ErrorPage` is now `core\error\Page`, and the security-header emission extracted from `core\ErrorHandler` is now `core\error\SecurityHeaders`. The `core\ErrorHandler` facade and its behavior are unchanged; update any project code that referenced `core\ErrorPage` directly.

### Fixed

- Transaction depth is now dropped before the `COMMIT`/`ROLLBACK` statement is issued. A statement that threw previously left the connection permanently one level deep, so every later transaction in the request nested a `SAVEPOINT` into a transaction that no longer existed. The rollback attempted after a failed commit also no longer replaces the caller's exception with "there is no active transaction", so the deadlock or lost connection that actually broke the write is the one reported.
- `Cache::remember()` releases its stampede lock even when the callback throws. The lock previously survived for its full TTL, so with a one-hour entry every request for an hour lost the lock, slept, re-checked a still-empty key and computed anyway.
- The logger no longer throws on an unrecognised level, regardless of validation: an unknown message level is treated as an error so it is surfaced rather than filtered away, and an unknown threshold falls back to the default. `Log::setLevel()` is public API, so the level can still arrive unvalidated at runtime.
- The login-throttle fallback used when APCu is unavailable behaved as a sliding window: it rewrote each counter's expiry on every failed attempt, so a host that kept failing held its own block open indefinitely — locking out everyone sharing that address. It now honors the documented fixed window, matching the APCu path.

### Security

- Login throttling no longer keeps a blocking per-username counter. It let an unauthenticated attacker lock any known account out of the application by distributing failed logins across source addresses — no credential needed, and no recourse for the real user. Both counters are now anchored to the source address: a tight `(username + IP)` counter, plus a looser per-IP counter that still caps one host enumerating many accounts.
- A session is now bound to the credential it was issued for. `Auth::login()` stores a fingerprint of the stored password hash and session revalidation re-derives and compares it, so **changing or resetting a password signs out fingerprinted sessions** instead of leaving them valid until they expire. Sessions created before this release carry no fingerprint and are upgraded in place on their first revalidation, so upgrading does not log everyone out; a password change made before a legacy session is first revalidated cannot revoke that session by fingerprint because no old fingerprint exists yet.
- Error pages no longer publish an unfilled CSP nonce. They render outside the Response/Extensions pipeline, so the security extension never substitutes the `{_csp_nonce}` placeholder, and `'nonce-{_csp_nonce}'` was emitted verbatim — a fixed, source-visible value that looked like a working control. The whole `'nonce-...'` expression is dropped instead, leaving error pages under the same policy without the nonce, which is stricter.

## [3.0.0] - 2026-07-12

### Changed

- **Breaking:** the default Content-Security-Policy `style-src` no longer includes `'unsafe-inline'`. Inline `<style>` blocks and `style="…"` attributes are now blocked by the browser unless the CSS is served as an external stylesheet. If your application relies on inline styles, move them into a stylesheet under `public/` (recommended) or add `'unsafe-inline'` back to `style-src` in `config/security.php`.
- The framework error page now loads its styles from `public/assets/css/error.css` instead of an inline `<style>` block, so the default error page still renders correctly under the tightened CSP.

## [2.0.0] - 2026-06-05

### Added

- `Db` equality conditions (`count`, `value`, `values`, `update`, `delete`) now accept `null` values (rendered as `IS NULL`) and array values (rendered as `IN (...)`, with an empty array matching nothing).
- Per-process DNS resolution cache for outbound egress host checks, avoiding repeated blocking lookups within a worker.

### Changed

- **Breaking:** renamed the `httpclient` and `errorlog` extensions to the hyphenated directories `http-client` and `error-log` (namespaces `extensions\http_client` and `extensions\error_log`); the class autoloader now maps underscored namespace segments to hyphenated directory names. Upgrading: rename `config/httpclient.php` → `config/http-client.php` and `config/errorlog.php` → `config/error-log.php`, and the `extensions.httpclient` / `extensions.errorlog` config keys to `extensions.http-client` / `extensions.error-log` — a leftover old key is silently ignored, so your settings under it won't apply — then clear the bundle cache on deploy.
- **Breaking:** configuration layers now deep-merge maps but replace lists wholesale instead of overlaying them by index, so a local or environment layer can shrink or clear a default list rather than only overwriting elements.
- **Breaking:** `Db::update()` now returns the number of rows matched by the condition rather than only the rows whose values changed; do not infer "did anything change?" from its return value.
- UUIDv7 identifiers are now strictly monotonic within a millisecond, improving database index locality for sequential inserts.
- Default configuration files now load in a deterministic, sorted order, making the merged result reproducible across filesystems.
- `Cache::set()` now reports success when APCu is unavailable, so cache writes degrade gracefully to the per-request memory tier instead of failing.

### Fixed

- The HTTP client no longer accumulates unreleased cURL handles across retries.
- `Cast::int` now rejects out-of-range integer strings instead of silently overflowing to a clamped value.
- Invalid UTF-8 in a log context value no longer discards the entire context payload.
- MySQL connections use the `Pdo\Mysql` class constants when available, avoiding the `PDO::MYSQL_ATTR_*` constants deprecated in PHP 8.4.

### Security

- The outbound HTTP proxy is now operator-config only (`extensions.http-client.proxy`); a request-supplied `proxy` option can no longer route traffic around the egress/SSRF allowlist.
- Request JSON bodies are decoded with a bounded nesting depth (`project.max_json_depth`, default 64), mitigating a cheap denial-of-service vector.
- SMTP STARTTLS connections now pin the expected peer name, closing a hostname-verification gap on the TLS upgrade.
- Disabling TLS verification for the HTTP client (`verify_tls = false`) is refused at startup unless explicitly acknowledged via `tls_insecure_acknowledged`.
- Database and SMTP configuration objects now redact their secrets from `var_dump()` and `json_encode()` output (gated by `log.redact_secrets`, on by default).
- The class autoloader validates every namespace/class segment, preventing path traversal through crafted class names reaching the loader.
- Operators can now tighten security allowlists (e.g. the egress allowlist, `log.redact_keys`) from a local config layer, since list values are replaced rather than index-merged.
- HTTP client redirects are followed manually with the egress/SSRF allowlist re-checked on every hop, so an allowlisted host can no longer redirect the request to an internal target.
- HTTP client redirects strip `Authorization`, `Proxy-Authorization`, and `Cookie` headers and drop the cookie jar when the target changes origin, preventing credential leakage across hosts.

## [1.0.0] - 2026-05-03

### Added

- Stable PHP 8.4+ framework core for small websites, internal tools, and simple SaaS projects.
- Explicit request lifecycle with front controller boot, host validation, route resolution, extension hooks, controller composition, layout wrapping, contributor tokens, response hooks, and response sending.
- Convention-based routing for language, module, controller, and optional ID segments, including template-only pages when no controller is needed.
- Extension hooks for custom behavior before and after requests, plus contributor hooks for layout tokens and conditional template blocks.
- Normalized URL path segments through the route segment parser, with invalid paths rejected before dispatch.
- Optional localized/public route path mapping for projects that need URLs different from internal module/controller names.
- Small template engine with escaped tokens, raw tokens, conditional blocks, and repeated row rendering.
- Core facades for request data, sessions, database access, cache, logging, config, formatting, identifier generation, and asset versioning.
- Compiled core, extension, and config bundles so warm requests load through a small fixed set of generated files.
- Two-tier cache with per-request memory and APCu, including shared-cache stampede protection.
- Plain PHP configuration files with local and environment overrides, typed DTOs for core config, and generated config bundles that never bake in local secrets.
- Auth extension with DB-backed users and groups, session login with session regeneration, path access rules resolved by user then group then global precedence, failed-login throttling, and `User::profile()` for project-owned session user data.
- CSRF extension with synchronizer tokens for unsafe HTTP methods.
- SEO extension with DB-backed per-route title, description, `canonical_url`, and `CANONICAL_URL` layout token fallback.
- Translation extension with route and language string tables, escaped text output, trusted HTML output, and cache invalidation helpers.
- Registry extension for DB-backed grouped key/value settings with language-aware lookups.
- Error log extension for DB-backed exception/error capture and retention-based cleanup.
- Flash, events, rate limit, security headers, profiler, validation, HTTP client, and mail extensions for common small-project needs.
- Database schemas for aliases, auth, SEO, translation, registry, and error logging.

### Security

- CSRF protection available as a synchronizer-token extension for unsafe HTTP methods, with token rotation after successful login.
- Authentication includes session-based login with session regeneration on login, group access control, session revalidation, login-attempt throttling, and path authorization rules.
- Rate limiting guards requests with APCu-backed per-IP counters.
- Security headers extension emits CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy, COOP, and CORP headers.
- Outbound HTTP client limits protocols through an allowlist and supports retry and timeout policy.
- Project secrets stay outside compiled defaults and are supplied through local config or environment variables.
- Route parsing rejects invalid URL segments before dispatch, and response helpers reject unsafe redirect targets.
- Host validation rejects requests outside the configured allowed host list.
- Error log extension redacts sensitive query parameters from URLs before storing exception records.
