# Simpra Framework

Simpra is a small PHP 8.4+ framework for small websites, internal tools, and simple SaaS projects.

It favors manual bootstrap, convention routing, static facades as the public API, and optional extensions over reflection, autowiring, service providers, or attribute-based routing. The goal is a framework you can read, reason about, and deploy without a large dependency stack.

All paths in this README and in `docs/` are written from the application root, meaning the contents of the `framework/` folder after you copy them into a project.

## Why It Is Built This Way

Simpra is deliberately small. It accepts some coupling and avoids patterns like dependency injection containers, reflection, auto-wiring, service providers, and attribute routing because the target is simple projects where clarity and speed matter more than framework extensibility.

This is not missing architecture. It is smaller architecture on purpose. See [docs/00-Philosophy.md](docs/00-Philosophy.md) for the full design rationale.

## Requirements

- PHP 8.4+
- Optional: APCu 5+ for shared in-memory cache
- Optional: MySQL 8+ or MariaDB 11+ for DB-backed extensions

A basic page does not require a database.

## Quick Start

```sh
git clone https://github.com/simpraio/framework my-project
cd my-project
php -S 127.0.0.1:8000 -t public
```

Open `http://127.0.0.1:8000`.

The web server document root must be `public`, never the project root.

## Local Configuration

Runtime defaults live in `config/*.php`. Keep environment-specific values in a gitignored local file:

```text
config/framework.local.php
```

`framework.local.example.php` is provided as a template. Copy only the values your environment needs.

The minimum local file is valid:

```php
<?php

declare(strict_types=1);

return [];
```

Common local development override:

```php
<?php

declare(strict_types=1);

return [
    'project' => [
        'url' => 'http://127.0.0.1:8000',
        'debug' => true,
        'allowed_hosts' => ['127.0.0.1', 'localhost'],
    ],
    'session' => [
        'secure' => false,
    ],
];
```

Production should prefer environment variables injected by PHP-FPM, the container, or the process manager:

```text
SIMPRA_PROJECT_URL=https://example.com
SIMPRA_DEBUG=0
SIMPRA_BUNDLE_DIR=/run/simpra
```

Database variables are required only when the app or enabled extensions use DB storage:

```text
SIMPRA_DB_DRIVER=mysql
SIMPRA_DB_HOST=127.0.0.1
SIMPRA_DB_PORT=3306
SIMPRA_DB_NAME=framework
SIMPRA_DB_USER=framework_user
SIMPRA_DB_PASS=secret
SIMPRA_DB_CHARSET=utf8mb4
```

## Project Layout

```text
app/         application services
cache/       generated bundle files, ignored by git
config/      committed defaults
core/        framework core
extensions/  optional bundled extensions
logs/        runtime logs, ignored by git
modules/     controllers
public/      web root and front controller
templates/   HTML templates

tools/apache/           Apache .htaccess example
tools/examples/         optional example modules/templates
tools/schema/           SQL schemas for DB-backed features
```

Generated bundle files are `core.php`, `extensions.php`, and `config.php` in the bundle directory. By default that directory is `cache`; override it with `SIMPRA_BUNDLE_DIR`.

## Optional Extensions

Most extensions are disabled by default. Enable only what your app actually uses in `config/{extension}.php`.

- Auth: DB-backed login, sessions, groups, access rules
- CSRF: unsafe-method token validation
- Error Log: DB-backed exception sink
- Events: synchronous in-process events
- Flash: one-request session messages and safe form errors
- HTTP Client: curl wrapper with policy controls
- Mail: native or SMTP transport
- Profiler: request timing for development
- Rate Limit: APCu-backed request throttling
- Registry: DB-backed key/value settings
- Security: HTTP security headers, enabled by default
- SEO: DB-backed route metadata
- Translation: DB-backed route/language text
- Validation: explicit input validator

DB-backed extensions use schema files from `tools/schema/*.sql`.

## Documentation

Start with [docs/00-Philosophy.md](docs/00-Philosophy.md), then continue to [docs/01-Getting-started.md](docs/01-Getting-started.md).

Full documentation index: [docs/README.md](docs/README.md).

## Static Checks

```sh
mago lint --minimum-report-level warning
mago analyze --minimum-report-level warning --reporting-format short
```

The committed `mago.toml` defines the project scan scope and rule thresholds.

## Deployment Notes

- Point the web server root to `public`.
- Keep `project.debug` false in production.
- Keep `session.secure` true on HTTPS.
- Ensure the bundle directory is writable by PHP.
- Ensure `logs` is writable when file logging is used.
- Apply SQL schemas only for enabled DB-backed features.
- Do not expose `tools`, `.git`, or local config files through the web server.

## License

MIT. See [LICENSE](LICENSE).
