# Upgrading

This guide lists the concrete steps to move between Simpra versions. It complements
[CHANGELOG.md](CHANGELOG.md): the changelog records what changed; this guide tells
you what to do.

Simpra is copied into your project rather than installed as a dependency. An
upgrade means: replace the framework files with the new release, apply the
version steps below, clear the bundle cache, and run your checks.

The installed framework version is recorded in `VERSION` at the project root.

Each version section is structured as Impact, Required, Conditional, and Good to
know. Keep this file small and practical; detailed release notes belong in
`CHANGELOG.md`.

## Principal Procedure

The easiest upgrade path is to let an AI/code assistant apply this guide, then
review the diff yourself. Give it this prompt:

```text
You are upgrading a Simpra project to the Simpra release in this package.

Read VERSION, UPGRADING.md, and CHANGELOG.md first.

Current project version:
- If VERSION exists, use it.
- If VERSION does not exist, inspect the project and infer the version from files, config keys, extension names, and CHANGELOG.md.
- If the current version is still unclear, apply only migrations whose old patterns are present and report the uncertainty.

Rules:
- Inspect the project before changing files.
- Make only the migration changes needed to reach the release in this package.
- Do not edit local secret files unless I explicitly ask:
  config/framework.local.php, framework.local.php, .env, credentials, logs, dumps.
- Do not print secret values.
- Do not delete user code.
- Do not install packages.
- Do not refactor unrelated code.
- Do not run destructive commands such as git reset, git clean, or recursive deletion.
- After changes, summarize the diff and list anything I must review manually.
```

After the AI finishes, review its diff, clear the bundle cache, run your tests,
and smoke-test staging with `project.debug = false`.

## Manual Procedure

1. Check your current `VERSION`, then read every version section between that version and this release. Changes are cumulative.
2. Upgrade on a branch, or back up the project first.
3. Copy the new framework files over the old framework files. Do not overwrite your own `config/`, `framework.local.php`, `app/`, `modules/`, `templates/`, `logs/`, or uploads.
4. Apply the Required steps for each version below, and any Conditional steps that match how you use the framework.
5. Clear the bundle cache:

   ```sh
   rm -f cache/*.php
   ```

   If you use `SIMPRA_BUNDLE_DIR`, clear that directory instead.

6. Run your test suite and smoke-test staging with `project.debug = false`.

## 4.0.0 -> 5.0.0

**Impact:** a maintenance window and data migration are required if the database contains
instant-valued `DATETIME` columns. Framework tables switch from local wall clock to UTC,
so existing rows must be converted before any v5 writer is allowed to run.

### Required

1. Back up the database and identify the timezone used by the previous
   `project.timezone`. For a named zone, verify that MySQL's timezone tables are loaded:

   ```sql
   SELECT CONVERT_TZ('2026-01-15 12:00:00', 'Europe/Prague', '+00:00');
   ```

   A `NULL` result means the named zone is unavailable; load the timezone tables before
   migrating. Use a fixed offset only for a timezone whose offset never changes.

2. Convert existing instant data in a write-free maintenance window, **in this order**:

   ```sh
   # 1. stop web workers, queue workers, cron jobs, and every other database writer
   # 2. deploy the v5 code while writers remain stopped
   # 3. pass the previous project.timezone to the same client session, then run:
   mariadb --init-command="SET @from_timezone = 'Europe/Prague'" \
     <database> < tools/schema/migration-utc-storage.sql
   ```

   Writers stay stopped until step 6. On MySQL substitute `mysql` for `mariadb`.

   Replace `Europe/Prague` with the previous storage timezone. The script defaults to
   `+00:00` when `@from_timezone` is not supplied, which is a no-op for projects that already stored
   UTC. It covers `auth_group.created_at`, `auth_user.created_at`,
   `auth_user.updated_at`, nullable `auth_user.last_login_at`, and
   `error_log.created_at`. The v4 login path formatted `last_login_at` in
   `project.timezone`, so it must be converted too.

   The supplied script expects both the auth and error-log schemas. Remove the statements
   for an extension whose tables are not installed. Run it through a client that stops at
   the first error, as the command above does; one that continues past an error or commits
   each statement can convert some tables and leave others local.

   Use an account that may `UPDATE` the framework tables, `CREATE`, `INSERT` into and `DELETE` from
   the three bookkeeping tables (`simpra_migration`, `simpra_dst_window`, `simpra_dst_review`), and
   `SELECT` from `mysql.time_zone_*`, which the DST pre-flight reads. The application's own
   least-privilege account is generally not the right one.

   Shifting the data twice would corrupt it, so the script records itself in a
   `simpra_migration` ledger table and a second run aborts on the duplicate key before
   touching a row. Only a run that actually converts is recorded, so a no-op run does not
   block the real one later. To re-run deliberately, restore the backup.

   A named zone applies the offset each value actually had, which a fixed offset cannot: for
   `Europe/Prague`, a June value shifts by two hours and a January value by one. Load
   `mysql.time_zone_*` first (`mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb mysql`) —
   without them `CONVERT_TZ` returns `NULL` for every row.

   The two DST edge cases cannot be skipped: `CONVERT_TZ` resolves both to a deterministic
   instant, so a wall clock in the nonexistent spring-forward hour lands on the transition
   boundary and one in the repeated fall-back hour is read as the first (pre-transition)
   pass. Both are guesses about data that never recorded which side it was on. Before
   converting anything, the script records every affected row — with its original local value —
   into a `simpra_dst_review` table, derived from the zone's real transition history. That table
   is the reconciliation work list, and it has to be captured before the conversion because the
   rows are indistinguishable afterwards. The console prints totals and the first 50 rows as a
   preview; read the complete list with:

   ```sql
   SELECT * FROM simpra_dst_review ORDER BY table_name, stored_local_value;
   ```

   Drop `simpra_dst_review` and `simpra_dst_window` once reconciliation is finished. Keep
   `simpra_migration` — it is the record that this migration ran.

   **Long-lived v4 writers corrupt the assumption this conversion rests on.** v4 computed a
   single numeric offset from `project.timezone` when the connection opened and kept it for
   that connection's life, so a writer that stayed connected across a DST transition went on
   stamping rows at the pre-transition offset. Those rows hold a wall clock that never
   existed locally, and this migration converts them with the offset the named zone says
   applied at that moment — moving them by a further hour. Persistent PDO connections, queue
   workers, cron daemons and long-running CLI processes are the usual carriers. Identify
   every v4 writer that outlived a DST boundary and reconcile the records it wrote after each
   boundary against audit logs or another UTC source; the migration cannot detect them and
   the report will not list them.

3. Read the skipped-row report the script prints last. Every count must be zero. A row whose
   conversion cannot be resolved is left as it was rather than written as `NULL`, so nothing
   is destroyed, but nothing is converted either:

   - a table-wide count means the named zone did not resolve at all. Nothing was changed and
     the migration was not recorded, so load `mysql.time_zone_*` and run the file again.
   - a handful of rows means those rows hold a date `CONVERT_TZ` cannot read, such as a
     legacy `0000-00-00`. Fix or delete them and convert them by hand.

4. Apply the same conversion to your own instant columns written under the old connection
   timezone, including columns with `DEFAULT CURRENT_TIMESTAMP`.
   One exception: a daily-rollup `day` column is a calendar label, not an instant, and
   belongs in the reporting zone. Do not convert it as an instant: shifting a 22:00 local
   event to UTC can place it in the next day's bucket.

5. Clear the bundle cache:

   ```sh
   rm -f cache/*.php
   ```

   A bundle is rebuilt only when missing, with no staleness check, so a surviving one keeps serving
   the previous release's compiled config — including silently re-enabling an extension the shipped
   config disables.

6. Only now resume writers and traffic. Everything above happens with every writer stopped: a v5
   writer running before step 4 stamps UTC into product tables that are still local.

### Conditional

1. If your own `templates/error.html` still contains `{isSQL}...{-isSQL}`, delete that
   block. The error page no longer supplies `isSQL`, `SQL_QUERY` or `SQL_BINDS`, and
   unhandled block markers render literally rather than being stripped.
2. If application code calls `Database::fromArray($raw, $timezone)` directly, remove the
   second argument. Database sessions are always UTC and the DTO no longer has a timezone
   property.
3. If any code builds a database connection with `PDO::MYSQL_ATTR_INIT_COMMAND` or
   `Pdo\\Mysql::ATTR_INIT_COMMAND`, remove it. The framework reserves that connection option
   for `SET time_zone = '+00:00'` and refuses a connection carrying an application value
   rather than silently overwriting it. Run additional session statements explicitly after
   connecting instead. (`database.options` only passes string keys through, so a PDO
   attribute constant set there was already being dropped before v5.)
4. If a `before()` hook redirects a URL after its controller and template were removed, replace
   it with a small controller returning `Response::redirect()` or a deployment-level redirect.
   Missing routes now return `404` before route hooks run, so they no longer reach the `ratelimit`
   extension. Use deployment infrastructure for broader traffic protection when your risk profile
   requires it.
5. Update scripts or CI jobs that call paths moved in v5:

   ```text
   tests/battery.php       -> tests/integration/battery.php
   tests/clear_cache.php   -> tools/clear-cache.php
   tests/bench/*           -> tools/bench/*
   ```

### Good to know

- A URL that maps to neither a controller nor a template now returns `404` before route hooks.
  This fixes deny-by-default authentication redirecting mistyped URLs to sign-in.
- `project.timezone` still controls what humans read. Database storage is always UTC and is
  not configurable.
- Error pages now send `robots: noindex, nofollow`.
- Anonymous requests no longer start a session unless application code, a flash message,
  CSRF, or another feature actually uses session state.
- The framework security probe can test a public form route with `--csrf-path=/login` and
  does not follow redirects while evaluating protected or missing routes.

## 3.0.0 -> 4.0.0

**Impact:** config check required before deploying. Configuration is now validated
at boot, so a project carrying an invalid value **stops starting** instead of
misbehaving quietly - that is why this is a major release. Auth behavior also
changes for password resets. No schema changes.

### Required

1. Audit your config for values that are now rejected at boot. Each one throws with
   the offending key named, so a single invalid value stops the application from
   starting:

   ```text
   log.level          debug | info | warning | error
   session.same_site  Lax | Strict | None
   any boolean key    must not be '' or whitespace - set true or false explicitly
   ```

   Check every layer, including `config/framework.local.php`, `framework.local.php`
   and `SIMPRA_*` environment variables, since any of them can supply the value.
   Spelling is matched case-insensitively, so `lax` is still fine. Note that
   `notice` and `critical` were never real log levels — earlier docs listed them by
   mistake, and a project that copied them was already breaking every log call at
   runtime.

2. Clear the bundle cache on deploy, as always:

   ```sh
   rm -f cache/*.php
   ```

### Conditional

1. If your project code references `core\ErrorPage` directly, it is now
   `core\error\Page`. The `core\ErrorHandler` facade is unchanged, so projects that
   only rely on the framework's error handling need no change.

### Good to know

- Changing or resetting a password now signs out sessions that already carry a
  credential fingerprint, on the next revalidation (within `revalidate_interval`).
  Plan for it if your password-reset flow assumed the current session survived.
  Sessions created before this release are upgraded in place on their first
  revalidation, so upgrading does not log everyone out; a password change made
  before a legacy session is first revalidated cannot revoke that session by
  fingerprint because no old fingerprint exists yet.
- A group change now takes effect within `revalidate_interval` instead of at the
  user's next login.
- `401` and `403` responses are now logged at `warning`. Expect new entries in the
  application log, and check your log volume if you run a noisy public endpoint.
- Login throttling no longer counts failures per username alone, so a third party
  can no longer lock an account out by failing logins against it from elsewhere.

## 2.0.0 -> 3.0.0

**Impact:** front-end only. Affects projects that enable the security headers
extension and use inline styles. No code changes, config renames, or cache steps
are required.

### Required

None.

### Conditional

1. If your templates use inline `<style>` blocks or `style="…"` attributes and you
   enable the security headers extension, the tightened default CSP
   (`style-src 'self'`, without `'unsafe-inline'`) will cause the browser to block
   them. Either:
   - move those styles into an external stylesheet served from `public/` (recommended), or
   - re-add `'unsafe-inline'` to `style-src` in `config/security.php`.

### Good to know

- The framework's own error page now loads `public/assets/css/error.css`. As long
  as you serve `public/` as your web root (the required setup), no action is needed.

## 1.0.0 -> 2.0.0

**Impact:** config-only for most projects. Code changes are needed only if you
use the renamed extensions directly, rely on `Db::update()` changed-row counts,
or depend on old list-merge behavior.

### Required

1. Rename the `httpclient` and `errorlog` extensions:

   ```text
   config/httpclient.php -> config/http-client.php
   config/errorlog.php   -> config/error-log.php
   ```

2. Rename config keys wherever they appear:

   ```text
   extensions.httpclient -> extensions.http-client
   extensions.errorlog   -> extensions.error-log
   ```

   In PHP arrays this usually means:

   ```php
   'httpclient' => ...
   'errorlog'   => ...
   ```

   becomes:

   ```php
   'http-client' => ...
   'error-log'   => ...
   ```

   Leftover old keys are silently ignored, so settings under them will not apply.

3. If your application imports the extension classes directly, rename namespaces:

   ```text
   extensions\httpclient -> extensions\http_client
   extensions\errorlog   -> extensions\error_log
   ```

4. Clear the bundle cache after deploying the new files.

### Conditional

- If you read the return value of `Db::update()`: it now returns the number of
  rows matched by the condition, not only rows whose values changed. Do not use
  it to answer "did the data change?"
- If a local or environment config layer extends a default list: lists are now
  replaced wholesale instead of merged by index. Restate the full list in the
  overriding layer if you need default entries to remain.
- If you use the HTTP client with redirects: redirects are now followed manually
  and each `Location` target is rechecked against the egress allowlist. When a
  redirect changes origin, `Authorization`, `Proxy-Authorization`, `Cookie`, and
  explicit cookie-jar use are stripped before the next hop.
- If you set `extensions.http-client.verify_tls = false`: startup now refuses it
  unless `extensions.http-client.tls_insecure_acknowledged` equals
  `I_ACCEPT_INSECURE_TLS`. Prefer providing a CA bundle for internal/self-signed
  hosts instead.

### Good To Know

- PHP 8.4+ remains required.
- Config DTOs holding secrets now redact those fields from `var_dump()` and
  `json_encode()` output when `log.redact_secrets` is true.
- `project.max_json_depth` bounds JSON request body nesting depth. Default: `64`.
- `Cache::set()` now succeeds when APCu is unavailable by writing only to the
  per-request memory tier.
