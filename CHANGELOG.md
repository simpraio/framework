# Changelog

All notable changes to this project will be documented in this file.

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
