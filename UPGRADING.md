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
