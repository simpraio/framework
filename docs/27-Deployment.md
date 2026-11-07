# Deployment

## One Request per Process

Simpra expects PHP to start each request fresh, as PHP-FPM, Apache's `mod_php` and the built-in server do. The static facades - `Config`, `Db`, `Session`, `Request`, the signed-in `User` and the per-request access rules - keep their state in static properties for the life of the request, and nothing resets them between requests. Do not run it under a worker runtime that serves many requests from one process, such as FrankenPHP worker mode, RoadRunner or Swoole: one visitor's user, session or database connection would carry over to the next.

## PHP Built-In Server

For local development only - no web server required:

```
php -S 127.0.0.1:8000 -t public
```

The `-t public` flag sets the document root to `public/`. Static files are served directly; everything else goes through `index.php`. Do not use this in production - it is single-threaded and not hardened.

## Apache

When using Apache with per-directory overrides, place this file at `public/.htaccess` to route all non-file requests through `index.php`:

```
# public/.htaccess
Options -MultiViews -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

Enable `mod_rewrite` and set the virtual host document root to `public/`. `AllowOverride All` is required for the `.htaccess` to take effect:

```
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/simpra/public

    <Directory /var/www/simpra/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Pass environment variables with `SetEnv` in the virtual host, or preferably in the PHP-FPM pool config if running PHP-FPM behind Apache (see [PHP-FPM Environment](#php-fpm-environment)).

## Nginx + PHP-FPM

Serve static files directly and forward everything else to `index.php`:

```
server {
    server_name example.com;
    root /var/www/simpra/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

Set deployment variables in the PHP-FPM pool config rather than the Nginx server block - Nginx does not forward `fastcgi_param` environment variables to PHP in all configurations. See [PHP-FPM Environment](#php-fpm-environment) below.

## Infrastructure Request Limiting

The optional `ratelimit` extension runs during application route hooks; missing routes do not reach it.
It is not infrastructure-level DoS protection. Deployments whose threat model requires protection
against arbitrary paths or volumetric traffic should configure it at their reverse proxy, CDN, WAF,
or hosting provider. Limits and trusted-client-IP handling are deployment-specific.

## PHP-FPM Environment

PHP-FPM clears environment variables by default (`clear_env = yes`). Set deployment variables explicitly in the pool config:

```
; /etc/php/8.4/fpm/pool.d/simpra.conf
clear_env = yes
env[SIMPRA_PROJECT_URL] = https://example.com
env[SIMPRA_BUNDLE_DIR]  = /run/simpra

; add only when DB-backed features are enabled
env[SIMPRA_DB_DRIVER] = mysql
env[SIMPRA_DB_HOST]   = 127.0.0.1
env[SIMPRA_DB_NAME]   = simpra
env[SIMPRA_DB_USER]   = simpra
env[SIMPRA_DB_PASS]   = secret
```

The framework can create the bundle directory recursively when the parent is writable. For production, pre-create it with the intended owner and permissions; for tmpfs paths, recreate it on service startup. For more detail, see [Configuration: Bundle Directory](02-Configuration.md#bundle-directory):

```
# systemd ExecStartPre or similar
install -d -m 700 -o www-data -g www-data /run/simpra
```

## File Permissions

Two directories must be writable by the PHP process. The framework creates them automatically on first request if they do not exist, but the parent directory must be writable:

```
cache/   - compiled framework bundles
logs/    - application logs
```

```
# Linux / macOS
chmod 755 cache/ logs/

# shared hosting may require
chmod 775 cache/ logs/
```

The bundles in `cache/` hold the committed `config/*.php` and the framework's own code, never
`framework.local.php` or environment variables, which are read on every request. They are written
owner-only (`0600`) all the same: a project that commits a value it should not would expose it there,
and a bundle is PHP the framework executes. For the same reason the bundle directory must not be
writable by any other account - a file another account places there runs as the web server - so never
point `SIMPRA_BUNDLE_DIR` at a shared directory such as `/tmp`. An account that cannot read a bundle rebuilds it, and that needs write access to
the bundle directory. When the web server and CLI jobs run under different accounts, this applies in
both directions: the web server rebuilds bundles a CLI job built, and a CLI job rebuilds bundles the web
server built. An account without write access fails with `Bundle: cannot write temporary file` - every
request, or every run of the job. With `chmod 755 cache/` owned by the web server, that is the CLI job.
Either run CLI jobs as the web server's account, or give both write access through a shared group
(`chmod 2770 cache/`); in the second case each rebuilds the bundles whenever it runs after the other.
New log files are closed to other accounts but keep the group bits the umask
allows: `0640` under `022`, `0660` under `002`. A shared group therefore needs umask `002` for both
accounts. Under `022` neither can append to a log the other created. Without APCu, the same holds for
a `Cache::once()` sentinel in `cache/`: only the account that created it can write it, so once it
expires the other account runs the work it throttles, such as the daily log prune, on every call until
the creator runs it again.

Both directories must be outside the web root - `cache/` and `logs/` are in the project root, which is one level above `public/`, so they are never directly accessible via HTTP. Do not move them inside `public/`.

## Updating Code or Configuration

The framework compiles `config/*.php`, the extension map, and the core class list into bundle files in the bundle directory (`cache/` by default). **These bundles are rebuilt only when missing - there is no staleness check against the source files.** After changing any config file, adding/removing/renaming an extension, or upgrading the framework, you MUST clear the bundle directory so it regenerates on the next request:

```
rm -f cache/*.php
```

`tools/clear-cache.php` deletes the framework's derived cache keys (templates, asset versions,
route aliases, SEO, translation, registry) only from the APCu of the process running it. APCu is
not shared between the CLI and the web server, so run from the shell it cannot reach the entries
your site is serving: reload PHP-FPM or Apache to clear those. It does not remove compiled bundle
files either, and therefore does not replace this deployment step.

If you deploy new code over a populated bundle directory without clearing it, the previously compiled config keeps being served - silently, with no error. A renamed config section, a newly disabled extension, or a new security setting is ignored, and the affected config DTOs fall back to their code defaults. Those match the framework's shipped config files, not your project's, so a stale bundle can **silently undo a project setting**, such as an extension you switched on or a default you tightened. Make clearing the bundle directory a mandatory step in your deploy pipeline: run it after the new code is in place and before the first request is served.
