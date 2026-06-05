# Getting Started

## What Simpra Is

Simpra is a small PHP 8.4+ framework for small websites, internal tools, and simple SaaS projects. It uses manual bootstrap, static facades, convention routing, and optional extensions instead of reflection, autowiring, service providers, or attribute-based routing. Minimum abstraction is a design goal - every layer between a request and its response is visible, intentional, and small enough to read in a sitting.

```
# local development only - not for production
php -S 127.0.0.1:8000 -t public
```

The web root must be `public/`. There is no Composer install step and no required database for a basic page. Database configuration is only needed when your app or enabled extensions query database storage.

## Prerequisites

Make sure you have the following before starting:

- **PHP 8.4+** - verify your version with `php -v`. Available via `brew install php` on macOS or `apt install php8.4` on Debian/Ubuntu. On Windows, use [windows.php.net](https://windows.php.net/download/) or a package like XAMPP.
- **Git** - required to clone the framework. Verify with `git --version`.
- **APCu** - optional for basic pages, but highly recommended in production. It provides the shared cache tier for templates, asset versions, route aliases, SEO, translation, registry, auth access checks, auth group lookups, and error-log purge guards. It is currently required only by the optional `ratelimit` extension; auth login throttling falls back to locked local files when APCu is unavailable.
- **MySQL 8+ or MariaDB 11+** - only needed when DB-backed extensions are enabled. You can skip this for a basic setup and add it later.

## Setup Steps

1. Install PHP 8.4 or newer and verify it is available with `php -v`. `pdo_mysql` is only required when DB-backed features are enabled; `apcu` is recommended for production caching and required when `ratelimit` is enabled.
2. Clone the framework: `git clone https://github.com/simpraio/framework my-project`
3. Add a `.gitignore` before making any commits. At minimum, exclude `framework.local.php`, `logs/`, and `cache/` so secrets and runtime files are never committed.
4. Create `config/framework.local.php` with your local project URL and debug setting - see [Add Local Config](#add-local-config) below for the exact content.
5. Run the built-in PHP server pointed at `public/`. You should see the framework welcome page at `http://127.0.0.1:8000`. If the port is busy, change `8000` to any free port. The first request compiles framework bundles and may take a moment longer than subsequent requests.
6. Choose which extensions your project needs. Open the relevant `config/*.php` file and set `'enabled' => true` to activate it.
7. If you enabled DB-backed features, configure the database and apply the extension schema. Schema files are at `tools/schema/{name}.sql`.
8. Build your app in `modules/`, `templates/`, and `app/`.

```
# 1. get the framework
git clone https://github.com/simpraio/framework my-project
cd my-project

# 2. protect secrets before the first commit (Linux / macOS)
echo "config/framework.local.php" >> .gitignore
echo "framework.local.php" >> .gitignore
echo "cache/*.php" >> .gitignore
echo "logs/*" >> .gitignore

# 3. create config/framework.local.php - see "Add Local Config" below for content

# 4. run the local server - open http://127.0.0.1:8000
php -S 127.0.0.1:8000 -t public
```

## Choose Extensions First

Extensions are optional. Start with the smallest set your app needs, then enable more when the behavior is actually used. To activate an extension, open its config file and set `'enabled' => true`.

```
# always-active config (not extensions)
config/app.php          project URL, timezone, allowed hosts
config/session.php      session cookie name, lifetime, security flags
config/log.php          log level, rotation, retention

# extensions - disabled by default unless marked [on]
config/auth.php         login and access rules
config/csrf.php         unsafe-method token validation
config/seo.php          title, description, canonical tokens
config/translation.php  route and language text
config/registry.php     DB-backed key/value settings
config/error-log.php    DB-backed error capture
config/events.php       in-process event dispatcher
config/flash.php        one-shot session messages across redirects
config/http-client.php  outbound HTTP with retries and TLS
config/mail.php         SMTP mailer - from address and transport
config/ratelimit.php    per-IP request throttling
config/profiler.php     request timing (development only)
config/security.php     HTTP security headers                    [on]
```

The security headers extension is **enabled by default** - review its Content-Security-Policy before going live. CSRF protection is **disabled by default** - enable it and add the hidden token field to every unsafe form before accepting user input.

## Add Local Config

For local development, the most portable setup is a local override file. Simpra reads `config/framework.local.php` first, and also supports `framework.local.php`. Add this file to `.gitignore` before your first commit - it holds secrets that must never be version-controlled.

```php
// config/framework.local.php
return [
    'project' => [
        'url'           => 'http://127.0.0.1:8000',
        'debug'         => true,  // never true in production
        'allowed_hosts' => [],    // set to ['yourdomain.com'] in production
    ],

    // add only when DB-backed features are enabled
    'database' => [
        'driver'   => 'mysql',
        'hostname' => '127.0.0.1',
        'database' => 'simpra',
        'username' => 'simpra',
        'password' => 'secret',
    ],
];
```

`'debug' => true` exposes stack traces and internal state - it must be `false` in production; see [Security: Error Exposure](25-Security.md#error-exposure). `allowed_hosts` restricts which Host headers the framework accepts; set it to your actual domain in production to prevent host header injection. See [Security: Host Header Validation](25-Security.md#host-header-validation).

## Database Is Optional

Simpra can serve pages without a database when your app and enabled extensions do not query one. Configure a database only when you need DB-backed features such as auth, SEO rows, translation rows, registry values, or error logs.

```
# example database
CREATE DATABASE simpra
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

Import only the tables required by the extensions you enable. Schema files are included with each extension at `tools/schema/{name}.sql`. Do not create tables for extensions that are not enabled.

## Project Shape

```
public/       front controller and public assets (CSS, JS, images, PDFs)
core/         framework internals
config/       plain PHP config files
modules/      controllers
templates/    HTML templates
extensions/   optional framework features
app/          your application code
tools/        maintenance scripts and SQL schemas (tools/schema/*.sql)
cache/        compiled framework bundles (generated on first request)
logs/         application logs (outside web root - never web-accessible)
```

Both `cache/` and `logs/` must be writable by the PHP process and are created automatically on first request. In production, you can point `SIMPRA_BUNDLE_DIR` at a tmpfs path or a directory outside the source tree; see [Configuration: Bundle Directory](02-Configuration.md#bundle-directory).
