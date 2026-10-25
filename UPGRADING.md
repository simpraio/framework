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

## 5.1.1 -> 5.2.0

**Impact:** one new optional extension and one new configuration section, plus several
behaviour changes that alter what reaches your servers and your database. A `GET` carrying
data is now sent as a `GET` instead of silently as a `POST`; rate-limit counters are
separated per policy, so effective limits change; redirect targets are resolved by RFC 3986
section 5, and twelve of that specification's forty examples previously resolved to a
different resource; and database work after a transaction level the framework could not
clean up is refused rather than carried out outside the transaction. A flattened project's
local configuration file is no longer compiled into the config bundle, which earlier releases
wrote out in plaintext. Auth rules and group status apply on the next request, a disabled
group keeps its denials, and a template block not granted to the user is hidden. There are no
schema changes. Read the three Required steps before deploying: the first change is silent,
and the other two concern credentials.

### Required

1. Review all `HttpClient::get()` calls, plus `HttpClient::request()` calls that explicitly
   select `GET`. Check those that set `data` or enable `raw`, including options
   assembled elsewhere. These options could previously make a `GET` arrive at the server
   as a `POST`: `CURLOPT_POSTFIELDS` makes cURL change the method on its own,
   and nothing here said otherwise. The verb is now stated explicitly, so
   the request is sent as a `GET`. **The payload does not move** - it stays in the request
   body, so the server now receives a `GET` carrying a body. Decide per call site: if the
   endpoint expected the `POST` it was in fact receiving, change the call to
   `HttpClient::post()`; if a real `GET` was intended, put the parameters in the URL query
   string, because most servers do not parse a `GET` body. Nothing reports this change - the
   call keeps succeeding and reaches a different handler, or none. `HttpClient::post()` is
   unchanged.
2. Delete the compiled config bundle after upgrading - `cache/config.php`, or `config.php`
   in `SIMPRA_BUNDLE_DIR`. The Manual Procedure already asks for this on every upgrade; this
   release makes it a security step. In a flattened project, where `framework.local.php`
   sits in `config/`, earlier releases compiled that file into the bundle, so the bundle
   holds a plaintext copy of the credentials set there: the database password, `crypt_key`,
   and any extension secret. The fixed loader no longer writes them, but an existing bundle
   is still read until it is removed - and with it any override you have since deleted from
   the local file. Treat copies of the old bundle (backups, deployment artifacts, other
   hosts) as holding those credentials, and rotate them if such copies exist outside your
   control. A framework checkout, whose local file sits beside `framework/`, was not
   affected.
3. If `log.redact_keys` is set, check the log files written before this upgrade. Earlier
   releases never applied those keys to the log file, so every value logged under one of them
   was written in the clear. Purge or restrict the old files if they hold secrets or personal
   data, and rotate any credential that reached them.

### Conditional

1. If `extensions.ratelimit` is enabled, expect different effective limits. Counters were
   keyed on the caller's address alone, so every budget in the application shared one
   counter and one expiry: the per-request application-wide check and a controller's own
   limit spent each other's allowance, and whichever ran first fixed the expiry for both.
   The key now covers `$max` and `$window` as well, and `Limiter::exceeded()` takes an
   optional fourth `$scope` argument to separate policies that differ only in where they are
   applied. Two consequences: budgets that were being drained by unrelated checks now have
   their own, so the application admits more requests than it did; and the new key orphans
   the counters already in the cache, so every limit starts from zero on deploy. Call sites
   sharing a `$max` and `$window` still share a counter unless you give them distinct
   `$scope` values. The existing three-argument call remains valid.
2. If application code catches an exception raised inside a `Db::transaction()` callback and
   then performs further database work, that work may now be refused with
   `RuntimeException('Database transaction is aborted')`. This applies when the framework
   could not undo the failed level - a `ROLLBACK TO SAVEPOINT` that itself failed - or when
   the backend ended the transaction on its own, as a deadlock does. Every enclosing
   callback stays blocked until it exits; a later operation outside those callbacks may
   reconnect. An inner level whose rollback **succeeds** does not block the outer level,
   which can still commit: catching an inner failure remains a supported pattern.
   Previously such code reconnected and continued outside any transaction, committing
   each subsequent statement on its own.
3. If you connect to MySQL over a network you do not control, configure TLS with the new
   `database.ssl` section - `ca`, `cert`, `key` and `verify_server_cert`. A CA or a client
   certificate/key pair requests TLS; an absent or empty section requests none, so existing
   deployments are unchanged. `cert` and `key` must be supplied together; otherwise database
   configuration is rejected when first used. The database connection is lazy, so this need
   not happen during boot. `verify_server_cert` defaults to true; configure the issuing CA
   rather than turning
   verification off. Framework configuration previously could not enable database TLS:
   SSL attributes are integer constants and `database.options` reaches the driver with
   string keys only, so a CA path put there was dropped and the connection stayed in the
   clear. If you did that, move it to `database.ssl` and confirm the connection is now
   encrypted. See
   [Database](docs/07-Database.md).
4. If an allowlisted outbound host resolves into the NAT64 well-known prefix
   `64:ff9b::/96`, the egress guard now judges it by the IPv4 address in its low 32 bits
   rather than by the prefix's own IANA attribute. `64:ff9b::808:808` stays reachable
   because `8.8.8.8` is; `64:ff9b::a9fe:a9fe` is refused because `169.254.169.254` is. A
   legitimate NAT64 path to a globally routable address is unaffected.
5. If application code relies on `extensions\validation` accepting values it should not,
   check those paths. An integer too large for the platform type is refused instead of
   saturating to `PHP_INT_MAX`; float validation refuses overflow and non-finite values
   (`NAN` previously bypassed both bounds); a date containing a NUL byte
   is a validation failure rather than an uncaught `ValueError` and a 500; and `int()` and
   `uuid()` refuse a value ending in a line feed, which they accepted - `uuid()` returned it
   with the newline still attached. Trim input first if a trailing newline is expected,
   for example from a textarea.
6. If `extensions.auth` is enabled and a login upgrades a low-cost bcrypt hash, a concurrent
   password reset, account deletion, or rehash can now make that login return `false`.
   The update matches the original hash in its `WHERE` clause, preventing a stale rehash
   from overwriting a newer credential. A retry may succeed after another login's rehash;
   after a password reset, the user must provide the new password. Stronger bcrypt costs
   and Argon2 hashes are left alone. Without a rehash, credential changes are detected at
   the next request-driven session revalidation, not immediately. See
   [Auth](docs/11-Extensions-Auth.md).
7. If you need a redirect not to be followed, `follow_redirects` is now a per-request HTTP
   client option. It defaults to true, which is the previous behaviour.
8. If you want Microsoft or Google sign-in, enable the new optional `oidc-login` extension.
   It needs no schema. Pass the page that sent the user to log in
   as the second argument of `Login::start()`, and a successful sign-in returns them there
   instead of to `success_redirect`; a value that is not a path on this site is ignored. See
   [OIDC Login](docs/18-Extensions-OIDC-Login.md) for setup and provider registration.
9. If `extensions.auth` is enabled, a guest turned away from a protected page is now
   redirected to `guest_route` with `?return=<the page they asked for>` rather than to
   `guest_route` alone - for a `GET` or `HEAD` request only; a refused `POST`, `PUT`,
   `PATCH` or `DELETE` still goes to `guest_route` with no target, because signing in
   would send the user back with a `GET`. Nothing breaks if your login form ignores it.
   To use it, read `return` and send the user there after a successful sign-in - validate
   it first, or pass it to `core\http\Url::isLocal()`, because it arrives from the
   query string. To switch it off, set `extensions.auth.return_param` to an empty string; do
   consider that if your URLs carry anything sensitive in a query string, since the target
   now appears in the sign-in URL and therefore in access logs and browser history. If you
   also use `oidc-login`, confirm its callback route has a guest rule: a refused callback is
   carried like any other page, and its provider `code` and `state` would land in the
   sign-in URL. Someone already signed in who is refused is unaffected: they still go to
   `denied_redirect` with no target, because signing in again would not grant them the page.
10. If you pass `extensions\http_client\HttpClient` a URL with credentials in it -
    `https://user:secret@host/` - it is now refused with `HTTP client URL rejected` before
    anything is sent. Move the credentials into an `Authorization` header in the request's
    `headers` option. A URL containing a space, a control byte or a backslash, or with a
    percent-encoded host, is refused too; encode the space as `%20`, and use `/` rather than
    `\`. A redirect to such a URL is refused as well, even with the egress guard off.
11. If `project.allowed_hosts` is set and something in front of your application rewrites the
    `Host` header, check it still sends a plain `host[:port]`. The guard now refuses a value with user information, a path or a
    query, which it previously judged by the host part alone, and answers 400.
12. If you set response headers with unusual names, check them: `Response::header()` now
    accepts only an RFC 9110 token as a name - letters, digits and ``!#$%&'*+-.^_`|~`` - and
    throws `InvalidArgumentException` for a space, a non-ASCII character or anything else it
    previously let through. A name or value in `extensions.security.headers` that breaks the
    rule is a config error naming the key, raised when the extension map is rebuilt after the
    bundle is cleared and whenever the security config is first read; fix it before deploying.
    The HTTP client drops a request header that breaks the rule, as it did for CR and LF; that
    now also covers a value containing a NUL byte, which cURL used to cut the value short at,
    and a name ending in a line feed.
13. If you call `HttpClient::request()` with a `method` outside `GET`, `POST`, `PUT`, `PATCH`,
    `DELETE`, `HEAD` and `OPTIONS` - `TRACE`, a WebDAV verb, a typo - it now throws
    `HttpClientException('HTTP client method rejected')`. It was sent as a `GET`, which is a
    different request from the one asked for and may have succeeded against the wrong
    handler. Leaving `method` out, or passing `null` or an empty string, means `POST` when
    `data` is set and `GET` when it is not; an empty string with `data` is therefore sent as
    the `POST` that earlier releases put on the wire, not as a `GET` carrying a body. The
    case of a valid method does not matter.
14. If `extensions.error-log` is enabled and `log.redact_keys` is set, the URLs it stores now
    have query parameters with those names redacted as well as the ones in its own
    `redact_keys`. `log.redact_keys` names the log-context keys your project treats as
    secret, and the same names in a stored query string are now treated the same way. A
    generic name there, such as `id`, also hides `?id=` values in stored URLs; move it to a
    more specific key if you need those for debugging. Rows already stored are unchanged.
15. If application code reads the Microsoft membership's raw group list, expect lowercase,
    unique, non-empty IDs, including lists restored from a session. Unknown membership still
    differs from a known empty list; authorization policy is unchanged.
16. If automation invokes `tests/integration/battery.php`, use `php tests/run_all.php`
    instead. Its assertions now run in the owning suites. Remove invocations of the obsolete
    `tools/bench/pipeline.php`; `tools/bench/http.php` remains a reporting-only benchmark.
17. If application code reaches into HTTP client internals, replace
    `curl\Options::normalize($options)` with `new curl\RequestOptions($options)`.
    `curl\Policy` and the validation extension's internal `NumberRules`, `TextRules`,
    `ChoiceRules` and `FormatRules` classes were removed. Use the documented HTTP client
    and `Validator` entry points rather than these implementation helpers.
18. If a JSON endpoint can return strings that are not valid UTF-8 - typically values read
    from a latin1 table or a legacy file - convert them before calling `Response::json()`.
    It now throws `JsonException`, which becomes a logged 500, where it used to answer 200
    with the body `null` and lose the whole response. `NAN` and `INF` throw too.
19. If `extensions.ratelimit` is enabled, requests that `auth` redirects or `csrf` rejects now
    count against the budget, since rate limiting runs first. A page that sends signed-out
    visitors round a redirect, or a form that fails CSRF often, spends more of it than before.
    If you wrote an extension whose hook depends on running before or after another, declare
    `public const int PRIORITY` on its `Boot` class: lower runs first, the default is `0`.
20. If `project.allowed_hosts` is set, check it is a list - a single host written as a string,
    or `null`, used to switch the check off silently and is now a config error - and that every entry is a
    host name alone. An entry with a
    port (`example.com:8443`), a path, a wildcard or a Unicode name now stops the boot with a
    config error. None of them ever matched - the port is not compared - so drop the port,
    list subdomains one by one, and write internationalised names in their `xn--` form.
21. If you use block rules in `auth_access`, a block configured for a path and not granted to the
    current user is now hidden. It used to be rendered, contents included, for everyone without
    a matching rule. Check the pages whose users relied on seeing such blocks, and add the grants
    they should have.
22. If auth groups are marked `disabled`, their grants stop applying and their denials still
    apply. Their users fall back to user-specific rules with `group_id = 0` and global rules;
    review those before deploying.
23. If you send mail over SMTP with a password, it is no longer trimmed. Remove whitespace from
    the configured password only if it is not part of the real credential.
24. If `extensions.auth` is enabled, login throttling checks the file-counter directory even
    when APCu is enabled. On POSIX, check that `simpra-auth-rl` under the workers' temp directory
    is not a link, not writable by group or others, and owned by the account PHP runs as where
    ownership checks are available. Otherwise login checks fail with
    `AUTH_RATE_LIMIT_DIRECTORY_NOT_PRIVATE`, not necessarily during boot. Windows skips these
    checks; configure restrictive ACLs there. Arrange counter retention as
    described in [Auth](docs/11-Extensions-Auth.md#counter-retention); the maintenance tool is
    not vendored, so copy `tools/prune-auth-counters.php` from the framework to the host.
25. If you call `Validator::uuid()` with a version, it must be 1-8; any other value now throws
    `InvalidArgumentException` where it used to match the wrong UUIDs.

### Good to know

Stack traces no longer show call arguments, in the log or on debug pages: the error handler
sets `zend.exception_ignore_args`. Frames, files and lines are unchanged. To see argument values
while debugging locally, set it back to `0` after the kernel boots.

A deprecation no longer ends the request. `E_DEPRECATED` and `E_USER_DEPRECATED` are logged
at warning level and execution continues; every other severity in the mask still stops the
request. A routine PHP upgrade now leaves notes in the log rather than taking pages down, so
expect new warning lines the first time you move PHP versions - they were previously 500s.

Redirect resolution moved into `extensions\http_client\Reference`, with section 5.2.4's
algorithm in `extensions\http_client\DotSegments`, leaving `Redirect` to decide only whether
a response redirects. `Redirect::target()` keeps its signature; only its answers change, and
only where they were wrong. Among the corrections: a trailing slash was dropped, so `g/`,
`.` and `..` all named a resource one level up from the right one; dot segments survived in
an absolute-path reference, so `/./g` was requested literally; a repeated slash was
collapsed, and `/a//b` is not `/a/b`; a fragment was read as part of the path, so
`g#s/../x` followed to `/x`; and a reference with no path took the base's directory instead
of its path, so `?page=2` returned from `/reports/list` requested `/reports`.

A database failure while the route-alias map is cold is no longer cached. `Aliases` returned
an empty map on error, which `Cache::remember()` then stored for the full TTL - an hour by
default - so a database unreachable for one second answered "this site has no aliases" long
after it recovered. The failure now propagates, nothing is cached, the next request retries,
and the error is logged where both catches were previously silent.

Reading a malformed JSON body twice now fails the same way twice. `Request::json()` marked
the body parsed before decoding it, so a second read returned an uninitialised-property
`Error` in place of the refusal.

`core\http\Url` is new and is where URL and path handling lives: `path()`, `isLocal()`,
`configuredPath()`, `parse()` and `host()`. If your own code calls `parse_url()` to judge a
URL - to check a scheme, compare origins or extract a host - `Url::parse()` gives the same
answer the framework uses, and refuses the inputs two parsers could read differently.
`extensions\auth\Config::redirectPath()` delegates to `Url::configuredPath()`; configured
values behave as before.

## 5.1.0 -> 5.1.1

**Impact:** none for most applications. Replace the framework files; there are no
configuration or schema changes. A redirect target or auth redirect value carrying an ASCII
control character is now refused where it was previously accepted or silently trimmed.

### Conditional

1. If `extensions.auth`'s `guest_route`, `denied_redirect` or `logout_redirect` holds an ASCII
   control character or DEL, it now fails during configuration loading as
   `INVALID_AUTH_REDIRECT_PATH` instead of being trimmed and reaching a `Location` header.
   Remove the control character; ordinary surrounding spaces are still ignored.
2. If application code passes such a value to `Response::redirect()` or `Auth::logout()`, it now
   throws `InvalidArgumentException`. The guard widened from CR, LF and NUL to every ASCII
   control and DEL, tab included.

### Good to know

Browsers strip tabs and newlines while parsing a URL and read a backslash as a slash, so a value
such as slash-tab-backslash-host passed the old local-path guard and was then interpreted as a
protocol-relative URL naming another origin. Rejecting the whole control range closes that
reading rather than trying to anticipate each browser normalisation.

## 5.0.0 -> 5.1.0

**Impact:** PHP `mbstring` is now an explicit framework requirement, and four behaviours
change: boot fails when a proxy is configured while the egress guard is enforcing addresses,
outbound calls to addresses that are not globally routable are refused, raw mail header bytes
are held to printable ASCII and tab while Unicode subjects remain RFC 2047 encoded, and an
auth redirect configured with a leading slash loses its trailing slash. There are no new
configuration keys or schema changes, but existing auth redirect values may need adjustment;
read the Conditional steps before deploying.

### Required

1. Verify that `mbstring` is enabled with `php -m`. On Debian/Ubuntu, install the
   package matching your PHP version, such as `php8.4-mbstring`. Mail composition
   now reports `MAIL_MBSTRING_REQUIRED` instead of failing with an undefined-function
   error when the extension is absent.

### Conditional

1. If your application constructs `extensions\mail\Envelope` directly for SMTP, you may
   pass the validated envelope sender as the optional fifth `fromEmail` argument. When it
   is omitted, the final valid mailbox in the `From` header is used. The original four
   constructor arguments are unchanged. Invalid sender or recipient addresses are rejected.
2. If your application sets custom mail headers through `$msg->header()`, check that the
   names are valid RFC 5322 field names, that the values contain **printable ASCII and tab
   only**, and that no rendered header line exceeds 998 octets. Anything else is dropped
   silently, as CR/LF-bearing and reserved headers already were, so a header that stops
   arriving is the symptom to expect. That rule is narrower than "valid UTF-8 without
   controls": a raw non-ASCII byte is rejected even in well-formed UTF-8, because raw UTF-8
   header fields need SMTPUTF8, which this client does not negotiate. Supply the ASCII form
   that the particular header's grammar calls for. An RFC 2047 encoded-word
   (`=?UTF-8?B?...?=`) is valid only where that grammar admits one - an unstructured value, a
   comment, or a display-name phrase - and never inside an address, token or quoted string
   (RFC 2047 section 5), so `Reply-To` encoded as one word is a field with no mailbox in it,
   which this extension cannot detect for you. Subjects, display names and attachment
   filenames are encoded for you and are unaffected. Only spaces and tabs are trimmed from a
   name or value, and the value is checked before it is trimmed, so a stray control at
   either edge drops the header rather than being stripped and the remainder sent.
3. If your application puts untrusted text in a mail subject, be ready for
   `InvalidArgumentException('INVALID_MAIL_HEADER_VALUE')`: invalid UTF-8, a line break, or
   any other control character except tab is now refused rather than flattened or
   base64-wrapped and delivered. Sanitize the text, or catch it where the message is built.
   `$msg->subject()` trims spaces and tabs only, so a subject that merely ends in a newline
   is refused now where it was previously trimmed and sent; trim it yourself if that is the
   behaviour you want.
4. If your application constructs `extensions\mail\Envelope` directly, it now refuses
   (as `INVALID_ENVELOPE_HEADER`) a subject or header value containing anything other than
   printable ASCII and tab, a name that is not an RFC 5322 field name, and any rendered line
   over 998 octets. Tab is legal header whitespace and is kept. A CRLF is accepted only where
   it begins a folded continuation line, which is what Composer produces.

5. If an allowlisted outbound host resolves through a flaky or split-horizon resolver, note that
   a lookup returning nothing is now a refusal rather than a pass. The guard validates addresses,
   so no addresses meant no check ran.
6. If you call a service whose address is not globally routable, it is refused now: the check
   widened from "outside the private and reserved ranges" to "globally routable", so carrier-grade
   NAT (`100.64.0.0/10`) and the benchmarking and documentation ranges are included. Special-purpose
   ranges that are globally reachable, such as the NAT64 prefix `64:ff9b::/96`, are unaffected.
   Allowlisting
   the host does not lift this - the address check runs after the allowlist. Either set
   `extensions.http-client.egress.block_private_ips` to `false`, which drops the address check for
   every host and leaves the allowlist as the only control, or reach the service through an
   endpoint that is globally routable.

7. If the HTTP client is enabled, `extensions.http-client.proxy` is set, and the egress guard
   is enabled with `block_private_ips`, boot now fails. A proxy and application-side address
   enforcement cannot both hold: the proxy resolves the destination itself, so the addresses
   validated here are not the ones dialled. Set
   `block_private_ips: false` to record that the proxy owns that policy, or drop the proxy. A
   disabled egress guard is unaffected, since `block_private_ips` does nothing there.

8. If `extensions.auth`'s `guest_route`, `denied_redirect` or `logout_redirect` is configured
   with a leading slash, its redirect target loses the trailing slash: `'/login'` sent a guest
   to `/login/` before and sends them to `/login` now. Both forms reach the same controller,
   because the router strips trailing slashes, so nothing 404s - but the URL your users and
   logs see changes, and a rewrite that canonicalizes to the trailing-slash form will now add
   a redirect rather than remove one. Write `'/login/'`, or the bare `'login'`, to keep the
   previous target. A value written as a bare name is unaffected.
9. If you call `Auth::logout($route)` with a route argument, it follows the same rule now.
   `Auth::logout('/bye')` redirected to `/bye/` before and redirects to `/bye` now; pass
   `'/bye/'` or `'bye'` to keep the previous target.
### Good to know

The egress guard hands the addresses it validated to cURL as `CURLOPT_RESOLVE`, so the name is
resolved once rather than twice. Every redirect hop and retry is authorized and pinned in turn;
within the short DNS cache window a retry reuses the addresses already validated rather than
resolving again. That guarantee covers direct
connections; through a proxy the destination is resolved at the proxy, which is why the two are
refused together. `CURLOPT_PROXY` is now always sent so a stray `http_proxy` or `ALL_PROXY` in the
environment cannot route outbound requests without the configuration saying so.

Long subjects are encoded into bounded RFC 2047 encoded-words, attachment filenames use
RFC 2231 continuations, recipient lists fold between addresses, and long display names are
encoded. Short ASCII subjects are unchanged.

For the auth redirects, the leading slash is the difference: `'login'` still becomes `/login/`,
`'/login'` becomes `/login`. If your canonical URLs carry no trailing slash, adding the slash to
the configured value removes one redirect from every protected page. Leading slashes and
backslashes collapse, so a configured value can only name a path on this site: `'//host'` and
`'/\host'` both become `/host`.

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
