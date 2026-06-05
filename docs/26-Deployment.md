# Deployment

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

Both directories must be outside the web root - `cache/` and `logs/` are in the project root, which is one level above `public/`, so they are never directly accessible via HTTP. Do not move them inside `public/`.

## Updating Code or Configuration

The framework compiles `config/*.php`, the extension map, and the core class list into bundle files in the bundle directory (`cache/` by default). **These bundles are rebuilt only when missing - there is no staleness check against the source files.** After changing any config file, adding/removing/renaming an extension, or upgrading the framework, you MUST clear the bundle directory so it regenerates on the next request:

```
rm -f cache/*.php        # or: php tests/clear_cache.php
```

If you deploy new code over a populated bundle directory without clearing it, the previously compiled config keeps being served - silently, with no error. A renamed config section, a newly disabled extension, or a new security setting is ignored, and the affected config DTOs fall back to their code defaults. Because several extensions default to `enabled = true` in code (the shipped config files disable them), a stale bundle can **silently re-enable an extension you intended to keep off**. Make clearing the bundle directory a mandatory step in your deploy pipeline: run it after the new code is in place and before the first request is served.
