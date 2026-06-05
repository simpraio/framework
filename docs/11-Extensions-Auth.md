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
            'revalidate_interval'   => 60,            // re-check session against DB every N seconds
            'default_policy'        => 'deny',        // 'allow' or 'deny' for uncontrolled routes
            'guest_route'           => 'login',       // redirect guests here when access is denied
            'denied_redirect'       => '',             // redirect authenticated but unauthorised users
        ],
    ],
];
```

The `auth_group`, `auth_user`, and `auth_access` database tables are required once auth is enabled. Schemas are in `tools/schema/auth.sql`.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Auth::login($username, $password)` | `bool` | Validates credentials against the database. Regenerates the session on success, increments the lockout counter on failure. Returns `false` on bad credentials, lockout, or a disabled account. |
| `Auth::logout(?string $route = null)` | `Response` | Destroys the session and redirects to `logout_redirect`, or to `$route` when provided. |
| `User::current()` | `object` | Returns the current session user object, or a guest object with `user_id = 0`. |
| `User::isAuthenticated()` | `bool` | Returns `true` when a user is logged in (user_id > 0). |
| `User::isGuest()` | `bool` | Returns `true` when no authenticated user is stored in the session. |
| `User::id()` | `int` | Current user's ID, or 0 for guests. |
| `User::group()` | `?string` | Current user's group name, resolved from the `auth_group` table. |
| `User::inGroup($groups)` | `bool` | Checks whether the current user belongs to one of the given group names (string or array). |
| `User::profile(?string $key = null, mixed $default = null)` | `mixed` | Reads the current session user object, or a single field value when `$key` is given. |
| `Access::pathBlocks($pathId)` | `array` | Returns a map of block name -> bool for the current user and path. Used to show or hide template blocks per user or group. |

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

**auth_user** - one row per user. The `password` column must contain a bcrypt hash - use `password_hash($plain, PASSWORD_DEFAULT)` to generate one. The `status` column controls whether the account can log in: `active`, `disabled` (blocked, counts against rate limit), or `deleted` (soft-deleted, invisible to login).

**auth_access** - access control matrix. A row with an empty `block` controls whether the route itself is accessible. A row with a non-empty `block` controls the visibility of a named section within a page template. `user_id = 0` and `group_id = 0` are wildcards that match any user or group respectively.

When multiple rows match the same path and block, the most specific row wins: user-specific first, then group-specific, then wildcard/global. This lets a user-level `allow` override a group-level `deny` for the same block.

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
