# Auth

## Configuration

Create `config/auth.php` in your project. All keys are optional - defaults are shown.

```php
return [
    'extensions' => [
        'auth' => [
            'enabled'               => false,
            'session_key'           => 'user.data',    // session key where user data is stored
            'guest_group'           => 'guest',        // group name for unauthenticated users
            'logout_redirect'       => '',             // path to redirect after logout
            'login_attempts'        => 5,             // max failed logins before lockout
            'login_attempts_window' => 900,           // lockout window in seconds (15 min)
            'revalidate_interval'   => 60,            // minimum seconds between request-driven DB checks
            'default_policy'        => 'deny',        // 'allow' or 'deny' for uncontrolled routes
            'guest_route'           => 'login',       // redirect guests here when access is denied
            'denied_redirect'       => '',             // redirect authenticated but unauthorised users
            'return_param'          => 'return',      // carries the asked-for page to guest_route; '' disables
        ],
    ],
];
```

### Returning a guest to the page they asked for

A guest turned away from a protected page is redirected to `guest_route` with the page they
asked for appended, so a sign-in can finish the journey:

```text
GET /calls?page=2   ->  302 /login?return=%2Fcalls%3Fpage%3D2
```

Only a path on this site is appended, it is URL-encoded so the target's own query string
survives, a `#fragment` in `guest_route` stays after it, and the sign-in page is never its own
target, however its path is written: `/login` and `/login/` are the same page. Only a request
sent as `GET` or `HEAD` carries a target; an unknown method such as `PROPFIND` does not count
as one. Signing in sends the user back with a `GET`, so a refused `POST` or `DELETE` would come
back as a request it never was; it goes to `guest_route` alone, and its body is lost either
way. Read the parameter in your login
controller and send the user there after a successful sign-in — validate it first with
`core\http\Url::isLocal()`, because it arrives from the query string.

Set `return_param` to an empty string to switch this off. It is worth considering: the target
appears in the sign-in URL, so it reaches access logs and browser history, which a project whose
URLs carry anything sensitive in a query string may not want. Other sites are not the concern:
the security extension's default `Referrer-Policy: strict-origin-when-cross-origin` sends them
only this site's origin. A project that loosens that policy changes this.

Someone who is already signed in and is refused carries no target. They go to
`denied_redirect` alone, because signing in again would not grant them the page.

### Redirect paths

`guest_route`, `denied_redirect` and `logout_redirect` accept either form, and the leading
slash is the difference:

| configured | redirects to |
| --- | --- |
| `'login'` | `/login/` |
| `'/login'` | `/login` |
| `'/login/'` | `/login/` |
| `''` | `/` |

A bare name is a route name and takes the `/name/` shape. A value that starts with a slash is
the path exactly as written, which is what a site whose canonical URLs carry no trailing slash
needs: configured as `'login'`, a guest is sent to `/login/` and the site's own rewrite then
sends them to `/login`, so every protected page costs two redirects instead of one.

The same rule applies to a route passed at the call site, so `Auth::logout('/bye')` redirects
to `/bye` and `Auth::logout('bye')` to `/bye/`.

Leading slashes and backslashes collapse, so a value can only ever name a path on this site:
`'//host'` and `'/\host'` both become `/host`. A browser reads a backslash in a URL as a
slash, so both would otherwise be protocol-relative URLs naming another origin. Values containing
ASCII control characters or DEL are invalid and fail during configuration loading.

The `auth_group`, `auth_user`, and `auth_access` database tables are required once auth is enabled. Schemas are in `tools/schema/auth.sql`.

`created_at`, `updated_at`, and `last_login_at` represent instants and are stored as UTC
`DATETIME(6)` values. Convert them to the user's display timezone only after reading. Projects
upgrading from v4 must convert existing values as described in [UPGRADING.md](../UPGRADING.md).

## Session lifetime and throttling

The next request that reads auth state after `revalidate_interval` has elapsed since the last check revalidates the session against `auth_user`. The default interval is 60 seconds; there is no background timer. An inactive session is not checked until it is used again. Setting the interval to `0` checks on every request that reads auth state. Revalidation destroys the session unless the account is still `active` and still holds the credential it logged in with. Practical consequences:

- Changing or resetting a password revokes sessions carrying the previous credential fingerprint at their next revalidation, including the session that made the change. Sessions created before this feature existed are adopted on first revalidation; if a password is changed before such a legacy session is adopted, there is no old fingerprint to compare.
- Setting `status` to `disabled` or `deleted` ends the user's existing sessions at their next revalidation, not just future logins.
- A group change takes effect at the next revalidation; the user does not have to log out and back in.

Login upgrades bcrypt hashes whose cost is below PHP's default, preserving higher bcrypt costs and other supported algorithms such as Argon2. The rehash update matches the original credential: if a reset, deletion, or another rehash wins the race, login immediately returns `false` without changing the session, clearing throttling, or stamping `last_login_at`. A retry can succeed after another login's rehash.

This immediate race check applies only when rehashing. Without a rehash, a password reset between reading the credential and issuing the session is detected through the fingerprint at the next revalidation, not immediately. The interval is therefore not an unconditional wall-clock revocation deadline.

Failed logins are throttled on two counters, both keyed on the request's source address: `login_attempts` failures for one `(username + IP)` pair, and four times that for the address as a whole. Nothing is counted per username alone, so a third party cannot lock an account out by failing logins against it from elsewhere. The address is `REMOTE_ADDR`, never a forwarded-for header, so it cannot be spoofed from outside - but that also means a deployment where PHP sees a proxy instead of the client shares one address counter across all users, and the address must be corrected at the front end for the limiter to distinguish them.

Counters live in APCu when available, otherwise in locked files under the system temp directory. Both are host-local, so behind several front-ends each one throttles independently.

The fallback directory is `simpra-auth-rl` in PHP's temp directory, created private to the account PHP runs as. Login throttling checks it even with APCu enabled. Another account controlling it could reset or plant counters: on POSIX, a link, group/other-writable directory, or foreign owner where ownership checks are available makes login checks fail with `AUTH_RATE_LIMIT_DIRECTORY_NOT_PRIVATE`. Windows skips these checks; the deployment must configure restrictive ACLs.

### Counter Retention

Prefer APCu for unattended production throttling. Fallback files expire logically but remain on
disk; monitor the PHP temporary directory's disk space and inode usage. The maintenance tool is
`tools/prune-auth-counters.php` in the framework; a vendored project does not carry `tools/`, so
copy that one file to the host. Run it as the application service user to inspect expired files:

```sh
php tools/prune-auth-counters.php
```

During a maintenance window, stop **all workers using that PHP temporary directory**, then run:

```sh
php tools/prune-auth-counters.php --apply --workers-stopped
```

Each run prints the directory it scans. It must be the workers' temp directory, which is not the
command line's under systemd `PrivateTmp` or a PHP-FPM pool's own `sys_temp_dir`: pass
`--temp-dir=PATH` to name it. A directory that fails the rule above is refused.

Live and locked counters, malformed data, symlinks and unrelated files are preserved. Restart the
workers after cleanup. Do not delete the counter directory while requests are running: a worker
may still hold a handle to an unlinked file and lose a newly recorded failed attempt.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Auth::login($username, $password)` | `bool` | Validates credentials and rotates the session on success (`Session::rotate()`: new ID, CSRF token dropped). Returns `false` on bad credentials, lockout, a disabled account, or a lost rehash race. Invalid credentials and disabled-account attempts count toward throttling; a lost rehash race does not. |
| `Auth::logout(?string $route = null)` | `Response` | Destroys the session and redirects to `logout_redirect`, or to `$route` when provided. |
| `User::current()` | `object` | Returns the current session user object, or a guest object with `user_id = 0`. |
| `User::isAuthenticated()` | `bool` | Returns `true` when a user is logged in (user_id > 0). |
| `User::isGuest()` | `bool` | Returns `true` when no authenticated user is stored in the session. |
| `User::setGuest()` | `void` | Makes the current request's user the guest for the rest of the request, without touching the session. |
| `User::id()` | `int` | Current user's ID, or 0 for guests. |
| `User::group()` | `?string` | Current user's group name, resolved from the `auth_group` table. |
| `User::inGroup($groups)` | `bool` | Checks whether the current user belongs to one of the given group names (string or array). |
| `User::profile(?string $key = null, mixed $default = null)` | `mixed` | Reads the current session user object, or a single field value when `$key` is given. |
| `State::sessionPayload($user, $passwordHash)` | `array` | Builds the session payload for an already-authenticated account: the row's own fields, plus `credential_fingerprint` derived from its current stored password value, plus `last_validated_at`; `password` is removed. Use it from sign-in paths outside `Auth::login()`, including external identity and impersonation flows. |
| `Access::pathBlocks($pathId)` | `array` | Returns a map of block name -> bool for the current user and path. Used to show or hide template blocks per user or group. |

### Signing someone in from somewhere else

`Auth::login()` is not the only way into a session. A project that authenticates against an
external identity provider, or lets an administrator impersonate, writes its own session - and
it has to write the **same payload**, or revalidation will read a session it does not fully
recognise. `State::sessionPayload()` is that shape:

```php
$user = State::sessionPayload($accountRow, (string)($accountRow['password'] ?? ''));

Session::rotate();                       // new ID, CSRF token dropped
Session::set(Config::enabled()->sessionKey, $user);
User::set($user);
```

Pass the value currently stored in `auth_user.password`. After a successful rehash, use the new
hash rather than the one originally read. External sign-ins and impersonation flows also bind
to the stored value, even when it is an unusable password sentinel. Pass `''` only when the
stored value is empty or `null`; substituting it for a non-empty value causes revalidation to
reject the session.

This helper only builds the payload. The caller must authenticate the identity, check that the
local account is active, and authorize any impersonation before writing the session.

The Boot class contributes two tokens and two blocks to the layout on every request:

```
{USER_ID}     // current user ID (absent for guests)
{USER_GROUP}  // current group name when resolvable, including the guest group

{IsAuthenticated} ... {-IsAuthenticated}  // shown only when logged in
{IsGuest} ... {-IsGuest}                  // shown only for guests
```

## Schema

Three tables are required for DB-backed authentication. Run `tools/schema/auth.sql` against your database before enabling the extension.

```
CREATE TABLE `auth_group`
(
    `group_id`   SMALLINT UNSIGNED             NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(50)                   NOT NULL,
    `status`     ENUM ('active', 'disabled')   NOT NULL DEFAULT 'active',
    `created_at` DATETIME(6)                   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`group_id`),
    UNIQUE KEY `name` (`name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE `auth_user`
(
    `user_id`       MEDIUMINT UNSIGNED                        NOT NULL AUTO_INCREMENT,
    `group_id`      SMALLINT UNSIGNED                         NOT NULL,
    `username`      VARCHAR(100)                              NOT NULL,
    `password`      VARCHAR(255)                              NOT NULL,
    `status`        ENUM ('active', 'disabled', 'deleted')    NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME(6)                               NULL,
    `created_at`    DATETIME(6)                               NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at`    DATETIME(6)                               NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `username` (`username`),
    KEY `group_id` (`group_id`),
    CONSTRAINT `fk_auth_user_group`
        FOREIGN KEY (`group_id`) REFERENCES `auth_group` (`group_id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- user_id / group_id = 0 is a wildcard (matches any user or group)
CREATE TABLE `auth_access`
(
    `path_id`  VARCHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `block`    VARCHAR(50)                                              NOT NULL DEFAULT '',
    `user_id`  MEDIUMINT UNSIGNED                                       NOT NULL DEFAULT 0,
    `group_id` SMALLINT UNSIGNED                                        NOT NULL DEFAULT 0,
    `policy`   ENUM ('allow', 'deny')                                   NOT NULL DEFAULT 'deny',
    PRIMARY KEY (`path_id`, `block`, `user_id`, `group_id`),
    KEY `user_id` (`user_id`),
    KEY `group_id` (`group_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```

**auth_group** - lookup table for user groups (e.g. `admin`, `editor`). Each user belongs to one group via `group_id` in `auth_user`.

**auth_user** - one row per user. The `password` column must contain a hash supported by `password_verify()` - use `password_hash($plain, PASSWORD_DEFAULT)` to generate one. The `status` column controls whether the account can log in: `active`, `disabled` (blocked, counts against rate limit), or `deleted` (soft-deleted, invisible to login).

**auth_access** - access control matrix. A row with an empty `block` controls whether the route itself is accessible. A row with a non-empty `block` controls the visibility of a named section within a page template. `user_id = 0` and `group_id = 0` are wildcards that match any user or group respectively.

When multiple rows match the same path and block, the most specific row wins: user-specific first, then group-specific, then wildcard/global. This lets a user-level `allow` override a group-level `deny` for the same block.

A rule of a disabled group, or of a group that no longer exists, grants nothing, but its denial
still applies: disabling a group never widens access. That includes the guest group: visitors
keep its id while it is disabled, so the routes and blocks denied to guests stay denied. User-specific rules with `group_id = 0`
and global rules are unaffected. `Access::pathBlocks()` answers every block configured for the
path: a block with no matching grant is `false`, so its content is never sent.

### Rule changes

A path's rules and the list of active groups are read once per request - one query each - so a change to `auth_access` or `auth_group` applies to the next request, on every host, without a fresh sign-in. Nothing needs clearing.

## Example

A login controller that uses Flash to return field errors on failure:

```
use extensions\auth\Auth;
use extensions\flash\Flash;
use extensions\validation\Validator;

public function compose(Template $template): Template|Response
{
    if ($this->request->isMethod('POST')) {
        $v = new Validator($this->request->all());
        $username = $v->string('username', trim: true);
        $password = $v->string('password');

        if ($v->ok() && Auth::login($username, $password)) {
            return Response::redirect('/dashboard/');
        }

        Flash::withErrors(['credentials' => 'Invalid username or password.']);
        return Response::redirect('/login/');
    }

    return $template;
}
```
