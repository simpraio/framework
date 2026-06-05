# Changelog

All notable changes to this project will be documented in this file.

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
