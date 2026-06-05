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
