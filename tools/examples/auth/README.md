# Auth Example

Minimal login/logout example for the bundled `auth` extension.

## Install

Copy these files into your application:

```text
tools/examples/auth/modules/main/Login.php     -> modules/main/Login.php
tools/examples/auth/modules/main/Logout.php    -> modules/main/Logout.php
tools/examples/auth/templates/main/login.html  -> templates/main/login.html
```

Then enable auth in `config/auth.php` or in your local config:

```php
'extensions' => [
    'auth' => [
        'enabled' => true,
    ],
],
```

Auth needs database credentials and the schema from:

```text
tools/schema/auth.sql
```

Create at least one active `auth_group` row and one active `auth_user` row.
`auth_user.username` can hold either a username or an email address.

## Access Rules

With the shipped `default_policy => 'deny'`, a route without an `auth_access` row is refused,
and the sign-in page is such a route: a guest redirected to `guest_route` (`/login/`) is refused
there too and redirected to it again, in a loop. A signed-in user is likewise refused `/logout`.
Allow both routes for everyone - `user_id = 0` and `group_id = 0` match anyone:

```sql
INSERT INTO auth_access (path_id, block, user_id, group_id, policy) VALUES
    ('main/login', '', 0, 0, 'allow'),
    ('main/logout', '', 0, 0, 'allow');
```

Every other route your users reach needs an allow row as well, including the page a sign-in
lands on: `/` is `main/info` with the default route settings.

## Routes

With the default route convention, these files create:

```text
GET  /login   show login form
POST /login   attempt login
GET  /logout  destroy session and redirect
```

The optional `return` parameter must be a local absolute path such as
`/dashboard`. External URLs, protocol-relative URLs, and control characters are
ignored to avoid open redirects.

If the CSRF extension is enabled, add your CSRF hidden field to the form.
