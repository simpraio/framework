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
Record the installed project version and the target package's VERSION separately before replacing files.

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
- After the migration steps and validation succeed, update the project-root VERSION to the target package's VERSION. If validation remains pending or fails, leave the installed VERSION unchanged and report that.
- After changes, summarize the diff and list anything I must review manually.
```

After the AI finishes, review its diff, clear the bundle cache, run your tests,
and smoke-test staging with `project.debug = false`. Once validation succeeds, ensure the
project-root `VERSION` records the target release; do not update it after a failed or partial upgrade.

## Manual Procedure

1. Record your current `VERSION` and the target package's `VERSION` separately, then read every version section between them. Changes are cumulative.
2. Upgrade on a branch, or back up the project first.
3. Copy the new framework files over the old framework files. Do not overwrite your own `config/`, `framework.local.php`, `app/`, `modules/`, `templates/`, `logs/`, or uploads.
4. Apply the Required steps for each version below, and any Conditional steps that match how you use the framework.
5. Clear the bundle cache:

   ```sh
   rm -f cache/*.php
   ```

   If you use `SIMPRA_BUNDLE_DIR`, clear that directory instead.

6. Run your test suite and smoke-test staging with `project.debug = false`.
7. Only after the upgrade steps and validation succeed, update the project-root `VERSION` to the target package's `VERSION`. Leave it unchanged if the upgrade is incomplete or a check fails.

## 5.2.0 -> 5.3.0

**Impact:** the compiled bundles and new log files are no longer readable by other accounts,
and a bundle the serving account cannot read is rebuilt instead of failing the request. One
new optional tool, `tools/migrate.php`, applies MariaDB migrations at deploy time. The error
page's blocks are renamed. A cache policy in `extensions.security.headers` no longer reaches
a response bound to one visitor or one request, which gets `Cache-Control: no-store` instead,
and any other response that sets a cache header itself, on the `Response` or with `header()`,
keeps it rather than taking the configured one (Good to know 3). This release also closes a
full review's findings: a controller answers on one URL spelling only, a logout ends a
session nothing had read, login attempts are counted before they are checked, and several
APIs now refuse input they used to accept or quietly drop - Conditional 5 to 18 name each
one. It takes fixes from Simpra Server too: a misspelt local config key or a malformed config
file now stops the boot, while an empty map over committed settings preserves those settings
instead of clearing them, so read Conditional 16 before deploying. Log context is redacted
by default (Conditional 17). There are no schema
changes. Every project needs both Required steps. If CLI jobs and
the web server run under different accounts, Conditional 1 applies as well: without it,
those jobs fail after the upgrade. A site that lets a CDN cache its pages should read
Conditional 3 if it uses a CSP nonce, and Conditional 4 if the CDN or a load balancer
authenticates to the origin with `Authorization`: those pages are no longer cached.

### Required

1. In your project's own `public/index.php`, change `is_file($cache)` to
   `is_readable($cache)`. The bundles are now written `0600`, so a `core.php` that another
   account built - a CLI job run after the cache was cleared - cannot be read by the web
   server. With `is_file()` the front controller still tries to `require` it, and every
   request fails until the file is removed. The framework's own loaders already make this
   check; the front controller is the one copy your project owns.
2. Rename the blocks in your project's own `templates/error.html` to PascalCase, as the
   handbook requires of every block: `is403`, `is404`, `isBug`, `isDebug` and `isNotDebug`
   become `Is403`, `Is404`, `IsBug`, `IsDebug` and `IsNotDebug`, closing markers included:

   ```sh
   perl -pi -e 's/\{(-?)is(403|404|Bug|Debug|NotDebug)\}/{$1Is$2}/g' templates/error.html
   ```

   The framework answers only the new names. A block left under its old name is not answered
   and stays in the page verbatim, markers and content alike, so the debug section's markup
   shows in production; its detail tokens stay empty there. `tests/core/error_handler.php`
   fails on any marker left in the project's error page.

### Conditional

1. If the web server and CLI jobs run under different accounts, neither can read the
   bundles the other built any more - 5.2.0 wrote them readable to all - so each rebuilds
   them, and a rebuild needs write access to the bundle directory (`cache/`, or
   `SIMPRA_BUNDLE_DIR`). With the usual `chmod 755 cache/` owned by the web server, every CLI
   job now fails with `Bundle: cannot write temporary file`. Run CLI jobs as the web server's
   account, or give both write access through a shared group (`chmod 2770 cache/`); in the
   second case each rebuilds the bundles whenever it runs after the other.
2. Log files created before this upgrade keep their mode. Restrict them once, for example
   `chmod 640 logs/*.log`, or `660` if the web server and CLI jobs share a group and both
   append. If another account reads the logs without being in their group, such as a log
   shipper, add it to the group: it no longer reads new files as "other".
3. If you let a CDN cache your pages through a public `Cache-Control` in
   `extensions.security.headers` and your CSP uses `{_csp_nonce}` - the shipped default
   does - your HTML pages are now sent with `no-store` and are no longer cached: a nonce
   is fresh for each response, and a cached one would be the same for every visitor. To
   keep them cached, use a CSP without a nonce (`script-src 'self'`, with no inline
   scripts) and remove `nonce="{_csp_nonce}"` from your templates.
4. If you let a CDN cache your pages through a public policy in
   `extensions.security.headers` and the CDN or a load balancer authenticates itself to the
   origin with `Authorization` - or passes on HTTP authentication guarding the origin - every
   response, error pages included, is now sent with `no-store`, so no page is cached: a
   request carrying credentials counts as bound to whoever sent it. Files the web server
   serves from `public/` directly are unaffected. To keep pages cached, send origin
   authentication in a header of its own and check it in the web server or your application.
5. If a link, an alias row or a client uses a URL segment with a leading, trailing or
   doubled hyphen (`/blog/my-post-`, `/blog/my--post`), or reaches a controller under a
   spelling other than its class's (`/blog/mypost` for `MyPost` on a case-insensitive
   filesystem), it now answers `404`. Each controller answers on one spelling, so a rule
   keyed on its path covers every way to reach it.
6. If a module directory has a hyphen (`modules/my-blog/`), its controllers now run: the
   kernel builds `modules\my_blog\Post`, as the autoloader maps it, where it used to build no
   valid class name and render the module's templates without their controller. Check that
   those controllers are what the pages should run, and that their namespace uses
   underscores.
7. `route.default_module`, `route.default_controller` and every entry of `language.available`
   must be URL segments - lowercase letters, digits and inner hyphens - and so must
   `language.default` while `language.available` is set. A route default with capitals is
   lower-cased; anything else, such as `en_US`, is now a config error on boot. A language code
   like `en_US` never matched a URL before either. With no languages in URLs, the default is
   only a lookup key and may stay as it is.
8. If your templates use the flash extension's block, rename it to PascalCase, closing
   markers included:

   ```sh
   grep -rlE '\{-?hasErrors\}' templates | xargs -r perl -pi -e 's/\{(-?)hasErrors\}/{$1HasErrors}/g'
   ```

   A block left under the old name is not answered and stays in the page verbatim.
   `Flash::hasErrors()` is unchanged.
9. Mail. `Message::to()` throws `InvalidArgumentException('INVALID_RECIPIENT')` when an item
   is not a valid address - `->to('a@x.test, typo@@x.test')` used to send to `a@x.test`
   alone - and an array item must hold one address: a comma outside quotes in it is refused.
   `"Display <x@y>" <real@z>` now goes to `real@z`. These are refused too; 5.2.0 sent some to
   only part of the list and some correctly, so check any you use:
   - an address or angle-addr outside quotes before the angle-addr where a comma is missing,
     as in `jan@x.test Eva <eva@x.test>`, which went to Eva alone, or
     `Jan <jan@x.test> Eva <eva@x.test>`, which went to Jan alone; or a quote after an
     address. An address used as a display name must now be quoted,
     `"jan@x.test" <jan@x.test>`;
   - a comment after the address, as in `Name <a@x.test> (work)` - drop it;
   - an unbalanced quote, as in `O"Brien <ob@x.test>` or `Jan\" <jan@x.test>`: a backslash
     escapes a quote only inside a quoted string, so neither example closes its quote;
   - a blank angle-addr, `Name < >`, and a stray `>`, `Jan <j@x.test>>`;
   - in a string, an item whose quoted strings hold a comma and an `@` between them, as in
     `"Doe, John (j@x.test)" <j@x.test>` or the local part `"a,@b"@x.test`;
   - a recipient string, or an array item, over 1 MiB.

   Not refused: a missed comma between display names holding stray quotes reads as one
   display name. `a" jan@x.test b" <eva@x.test>` goes to Eva alone, as in 5.2.0; with an
   angle-addr inside the quotes, `a" <jan@x.test> b" <eva@x.test>` now goes to Eva where
   5.2.0 sent it to Jan. Display names are not sent, so pass an array of bare addresses when
   they come from users. A list holding one invalid item throws `INVALID_RECIPIENT`, where
   5.2.0 threw `NO_VALID_RECIPIENT` if no valid item was left. An address holding a control
   character is refused, also by `Envelope`; given to `from()`, it falls back to the
   configured address as an invalid one does. `Cc`, `Bcc`, `Content-Transfer-Encoding` and
   header names starting with `.` set with `header()` are now dropped without an error. Over
   SMTP a `Bcc` was never delivered and was shown to every recipient; with the `native`
   transport `Cc` and `Bcc` were delivered and now are not. Send a separate message instead.
   A message with only a text or only an HTML body is now a single `text/plain` or
   `text/html` part; a test asserting `multipart/alternative` for one needs updating.
   `extensions.mail.smtp.timeout` now bounds each server reply as a whole, and a reply over
   64 KiB fails with `SMTP_RESPONSE_TOO_LARGE`.

   **Check `extensions.mail.smtp.encryption` on every host before deploying.** It accepts only
   `none`, `ssl` or `tls`, in any letter case. Any other value, an empty string included, used
   to fall back to `tls` and work - `starttls` among them - and now throws `Config:
   'extensions.mail.smtp.encryption' must be one of none, ssl, tls`. The error appears at the
   first `Mail::send()`, not at boot, so a deploy that boots can still have every mail fail. Set
   `tls` to keep the old behaviour.

   Once the message data has been sent, a lost connection, a timeout or an unreadable reply now
   throws `RuntimeException('SMTP_DELIVERY_UNKNOWN: <cause>')`, where it threw the cause's own
   code (`SMTP_EMPTY_RESPONSE`, `SMTP_TIMEOUT`, ...). The message may have been delivered: if
   your code retries failed sends, do not retry this one automatically - record it and confirm
   delivery out of band (the relay's logs, the mailbox) before resending. `SMTP_CONNECT_FAILED`
   with no reason available is now the bare code, without a trailing `: `, so match the code
   rather than `SMTP_CONNECT_FAILED:`.
10. HTTP client. `timeout`, configured or per request, now bounds the whole call - every
    attempt, retry delay and redirect hop; raise it if a call legitimately needs more. An attempt
    that runs out of time throws `HttpClientException` with `$curlError === 28`; when too little
    time is left to retry, the call throws the last attempt's own error instead. A redirect from
    `https` to `http` now fails with `HTTP client redirect downgrade rejected`: fix the
    endpoint, or set `follow_redirects` to `false` and handle `Location` yourself.
    `HttpClientException::$url` holds only `scheme://host[:port]`; keep the URL you requested
    if you need its path. A redirect to another origin - a different scheme, host or port -
    now forwards only `Accept`, `Accept-Encoding`, `Accept-Language`, `User-Agent`,
    `Cache-Control` and `Pragma`: `X-Api-Key`, `X-Auth-Token`, any other custom header, the
    `cookies` jar and the body stay behind, and a redirect that carried `data`, whatever its
    status, is followed as a bodiless `GET`. An API that redirects to another host and expects
    its credential header or payload there now answers 401/403, or sees a GET where it
    expected a POST: call with `'follow_redirects' => false`, check the `Location` against the
    hosts you trust and
    repeat the request there, or call the final URL directly. Same-origin redirects are
    unchanged.
11. Validation. `Validator::in()` returns the matched choice in the choice's type -
    `in('n', [1, 2])` with input `'2'` returns int `2` - so review strict comparisons on its
    result. `datetime()` sets fields its format leaves out to zero: `Y-m-d` is midnight, and
    `H:i` falls on 1970-01-01 rather than today. `string()` and `pattern()` refuse input that
    is not valid UTF-8. Also:
    - `email()` refuses control characters, whitespace, `"`, `\`, `<` and `>`, so quoted local
      parts such as `"john doe"@example.com` fail with `email`; use `custom()` if you need
      them. The mail extension still accepts a quoted local part without control characters.
    - An optional `email()`, `uuid()` or `datetime()` given a non-empty array, a number or a
      boolean now fails with the rule's code instead of being skipped, and a required one
      reports that code instead of `required`. Send `null` or omit the key to mean absent; `[]`
      is still read as absent there, while `string()` and `pattern()` refuse any array.
    - `string()` and `pattern()` refuse floats and booleans with `string`; integers are still
      read as decimal strings. Use `bool()` or `float()` for those fields.
    - `bool()` given whitespace alone - a space, a tab, a line break - used to return `false`
      with no error; a required one now fails with `required` and an optional one is absent. If
      a form sends whitespace for "unchecked", pass `optional: true` and treat `null` as false,
      or send `'0'`.
    - A field that failed any rule is no longer in `values()`; the rule call's own return
      value is unchanged.
    - `pattern()` fails a value that exhausts PCRE's backtrack, recursion or JIT stack limit
      with `pattern` instead of throwing. A malformed pattern still throws
      `InvalidArgumentException`, under the framework's error handler too, where it used to
      surface as an `ErrorException`.
12. `ratelimit`: `enabled` defaults to `false` when the key is missing - the shipped
    `config/ratelimit.php` sets it - and `max` or `window` below 1 is refused when the
    configuration is read, even while the extension is disabled. `error-log`:
    `retention_days = 0` now keeps every row; it used to empty the table once a day.
13. `auth`: an empty `session_key` is a config error. Login throttling now counts every
    attempt before checking it and keys the account counter on the account, so account
    counters written before the upgrade, in APCu or the file fallback, are no longer read
    and expire - the per-address counters are unchanged; `tools/prune-auth-counters.php`
    removes the files once expired. Code of your own that called `RateLimit::blocked()`,
    `fail()` or `clear()` must change its flow, not only the names: call `ipAttempt()` and
    then `accountAttempt($subject)` before checking the credentials - both count the attempt
    and return `false` once it is over the limit, and nothing replaces `fail()` - and
    `succeeded($subject)` after a successful login. Use the subject `Auth::login()` uses,
    `'user:'` plus the account id, or `'name:'` plus the lower-cased name when no account
    matches, to share its counters.
14. Tooling, after you re-vendor `tests/` and `AGENTS.md`:
    - `tests/run_all.php` fails a generic suite that exits 0 without printing a `[PASS]` or
      `[SKIP]` line. Your own suites in `tests/app/` run under your `tests/run.php` and are
      unaffected.
    - `tools/verify-vendored.php` compares the blobs committed at `HEAD`, so commit a
      re-vendor before verifying it: an uncommitted file shows as
      `MISSING <path> (not committed)`, and a case-only rename as
      `MISSING <path> (committed as <other>)` plus `EXTRA <other>`. It notes uncommitted
      changes it does not compare, compares `CLAUDE.md` with the one line `@AGENTS.md`, and,
      run from a framework export without `tests/`, verifies `core/` and `extensions/` and
      names the suite as not in the framework.
    - Generic suites that need `framework.local.php` - for the database or the timezone -
      now skip without one, so a project configured only by `SIMPRA_*` variables gets skips
      rather than fatal errors.
    - A project holding both `framework.local.php` and `config/framework.local.php` now runs
      its tests against the `config/` one, as the application already did.
    - If you copied `tools/clear-cache.php`, copy it again: the old one required `tests/`. It
      reaches only its own process's APCu, so reload PHP-FPM or Apache to clear the web
      server's.
    - If you copied the auth example, add the `auth_access` allow rows for `main/login` and
      `main/logout` that `default_policy = 'deny'` needs (see its README), rename
      `{LOGIN_ERROR}` to `{HasLoginError}`, and check the return target with
      `Url::isLocal()`.
15. If your code constructs `core\config\Compiler` itself with required paths, check them
    after `compile()` yourself, as `Config::database()` does: `new Compiler($files, $env,
    requiredPaths: [...])` is now `new Compiler($files, $env)`. Passed by name, the argument
    fails with `Unknown named parameter`; passed by position, PHP ignores it without an error,
    and the check silently stops. The framework itself never passed one.
16. Configuration. Local keys flagged as misspellings, incompatible map replacements and
    malformed config files stop the boot. Check each point before deploying. Each host has
    its own local file and environment, so check on the host, from the new release's
    directory, before switching to it:

    ```sh
    php public/index.php
    ```

    A boot-time configuration refusal exits 255; check PHP's error log for the `Config: ...`
    error. If boot succeeds and `project.allowed_hosts` is non-empty, the CLI request stops
    at `Bad Request`, because it carries no `Host`. With an empty allowlist, the Host guard
    is off and this command can dispatch an application route instead.
    This is not a complete configuration or connectivity check: database configuration and
    some extension settings are validated only when first used. For a DB-using application,
    explicitly call `core\config\Config::database()` after its bootstrap to validate the
    database settings, then execute `SELECT 1` through the application's configured database
    connection. Exercise the enabled extensions on staging too. Run these checks with the
    serving account's configuration and environment; a CLI environment may differ from FPM's.
    Run the command as the serving account (`sudo -u www-data php public/index.php`), or
    clear the bundle directory afterwards: it builds owner-only bundles, even for a refused
    configuration, and the web server cannot read bundles another account built.
    On a live request, a boot-time refusal goes only to PHP's own error log - the FPM or web
    server log - and the browser gets a blank 500, since `display_errors` is off from the start
    of `Kernel::boot()`.
    - A local config key within one edit of a key your committed `config/*.php` declares - two
      for keys of eight or more characters; letter case is ignored and two swapped neighbours
      count as one - stops
      the boot with an error naming the key and what it resembles, never a value. Before, such
      a key was ignored, so `project.allowed_host` left the Host guard off and
      `extensions.csrf.enabeld` left CSRF off. Fix the spelling, or declare the key in a
      committed config file if it is meant, and clear the bundle after changing `config/*.php`:
      the check compares against the bundled defaults. Only a key shaped like its match is
      judged: a map near a declared map must share at least one of its keys - so a local-only
      section of your own such as `blog`, `routes` or `sessions` boots beside `log`, `route` and
      `session`, while `'sesion' => ['secure' => false]` stops the boot - and a scalar or list
      must sit near a key that is not a map. Near `extensions.security.headers`, a map inside
      your own section, or a declared empty array such as `database.options`, any map is judged.
      Keys resembling none, `[]` near a map, integer keys such as PDO attributes, header names
      under `extensions.security.headers` and keys below the first level of your own sections
      are not judged.
    - A map an earlier layer set can only be changed key by key. `[]` over it - `'session' =>
      []`, `'extensions' => ['security' => []]` - now changes nothing; before, it wiped the
      committed settings, `session.secure` and every security header included. Replacing a map
      with `null`, `false`, a string or a list stops the boot with `Config: '<key>' is a map of
      settings; a later layer can change its keys but not replace it with <type>`. Set the
      map's own keys instead: `'enabled' => false` for an extension, `''` for one header.
      `[]` over a list still clears it.
    - A config file that returns anything but an array keyed by section name - a list, or no
      `return` - stops the boot with `Config: <file> must return an array keyed by section name`.
    - A setting missing from every layer now falls back to the committed default:
      `session.secure` to `true`; `enabled` to `false` for `error-log`, `events`, `flash`,
      `http-client`, `mail`, `registry`, `seo` and `translation`; `http-client.timeout` to
      `60`; Microsoft OIDC `scopes` to `openid profile email`. An extension you use whose
      `enabled` no layer sets is now off: add `'enabled' => true`. A plain-HTTP host that relied
      on `session.secure` being absent now gets an HTTPS-only cookie: set
      `'session' => ['secure' => false]` in that host's local file, never in committed config.
17. Logging.
    - Log context is redacted even with `log.redact_keys => []`. Values under `crypt_key`, and
      under any key ending in `password`, `passwords`, `passwd`, `pwd`, `authpw`, `passphrase`,
      `secret`, `secrets`, `token`, `tokens`, `credentials`, `authorization`, `cookie`,
      `cookies`, `apikey`, `privatekey`, `privatekeypem`, `accesskey`, `secretkey`,
      `signingkey`, `hmackey`, `masterkey` or `encryptionkey` - so `HTTP_AUTHORIZATION`,
      `REDIRECT_HTTP_AUTHORIZATION`, `Proxy-Authorization`, `HTTP_COOKIE`, `Set-Cookie` and
      `PHP_AUTH_PW` too - are written as `[REDACTED]`, nested and inside logged objects; names
      compare with case and `_`, `-`, space and `.` ignored. `log.redact_keys` now adds to these
      and cannot remove one. Do not rename a secret such as `reset_token` to bypass redaction;
      log a separate non-secret correlation ID instead. Runtime tokens not held in the
      configuration are not covered by configured-secret value stripping. A count named in
      the plural is redacted as well (`max_tokens`): log it as `token_count`. Redaction goes
      by key name, so a list of header
      name/value pairs is not redacted. The `error-log` table's `url` column scrubs query names
      by the same rule.
    - Configured secret values of 8 bytes or more - the database and SMTP passwords, OIDC client
      secrets, anything the configuration holds under a secret key - are now replaced with
      `[REDACTED]` wherever they appear in a log message or context string. A dev password that
      is also an ordinary word (`password`) is redacted wherever that word appears: use a real
      secret. Code that constructs `core\log\Writer` itself gets this only by passing
      `secrets: Config::secrets(...)`; existing calls work unchanged. They are also stripped from
      context keys (a key that strips to a name already taken in the same array gets `#2`, `#3`
      and on), from the `error-log` table's `message` and `trace`, and from the lines
      `ErrorHandler` writes to PHP's own error log. A key ending in a secret suffix that holds a
      public value of 8 bytes or more - `session_cookie` holding a cookie name, `csrf_token`
      holding a field name, an endpoint map's `token` URL - has that value stripped from every
      line: rename it (`session_cookie_name`, `token_url`).
    - A context nested deeper than 32 levels, holding more than 5,000 values, or exceeding a
      shared 256 KiB allowance for keys and retained strings before JSON escaping is now cut,
      with `"[DEPTH]"` or `"[TRUNCATED]"` where it was cut. Objects are walked before encoding;
      plain objects expose public stored properties, not purely virtual hooks. Implement
      `JsonSerializable` when computed properties belong in the log. An array's own entries are
      counted first, so a large list is cut before a key logged after it.
      Dates retain their native fields, but custom public stored properties on a date or date
      subclass are walked under the same limits and redaction rules, never encoded ahead of them.
    - Collecting configured secrets is limited to 32 map levels, 5,000 entries and 256 KiB of
      keys and unique secret values. An incomplete or failed scan now omits the caller's
      message and context, writing `[REDACTED: configured secrets could not be collected safely]`
      instead for the lifetime of that writer; the `error-log` table and `ErrorHandler`'s
      `error_log` lines store the same marker instead of the text. Remove recursive or oversized custom config
      structures if that marker appears; no partial secret list is used for redaction.
    - Log lines no longer carry raw control or bidirectional characters: a parser sees `\r`,
      `\n`, `\t`, `\0` or `\uXXXX`, and a CR/LF in a message shows as `\r\n` instead of a
      space. Invalid UTF-8 becomes `?` in the message and U+FFFD in context. Strings over
      64 KiB end in `...[truncated N bytes]`.
    - A failed log write no longer throws out of `Log::*()`. Watch PHP's own error log - the
      FPM or web server log - for `Logger: cannot write`: nothing else reports a broken log
      directory.
18. Database (MySQL).
    - Statements and logins now give up after `database.read_timeout` (default 300 s, 1 to
      86400) instead of mysqlnd's 86400 s, and connects after `database.connect_timeout`
      (default 10 s, 1 to 300). A value outside its range fails at the first database use.
      Raise `read_timeout` for longer queries. A statement that times out fails with error
      2006 and may still complete on the server, so an autocommit write may have been applied.
    - `Db::lock($name, $timeout)` throws if `$timeout` is negative or not below
      `database.read_timeout`: replace `-1` (wait forever) with a finite timeout.
    - Inside `Db::transaction()`, a deadlock (1213), exhausted lock table (1206), lock wait
      timeout (1205), MariaDB 1020 or a lost or killed connection (2006, 2013, 1927, 4031) now
      rolls the transaction back at once.
      Catching it and carrying on no longer works: the next statement and the commit throw
      `Database transaction is aborted`. Retry the whole `Db::transaction()` call instead.
      Catching a duplicate key (1062) and continuing still works.
    - After a lost or killed connection (2006, 2013, 1927, or 4031 from MySQL 8.0.24+ after
      `wait_timeout`), a statement that exceeded
      `read_timeout` included, the next call opens a new connection instead of failing with 2006
      for the rest of the process; the old session's variables and temporary tables are gone.
      Inside a `Db::lock()` callback the lock went with the connection, so every later call in
      that callback throws `RuntimeException` (`Database connection was lost while Db::lock()
      held its lock`) instead of a 2006 `PDOException`: a `catch (PDOException)` there no longer
      catches it. If the release at the end of the callback finds the connection lost,
      `Db::lock()` no longer throws for it, since the lock went with the session; code that
      caught that exception after the callback no longer sees it. It logs the warning
      `Db::lock() found its connection lost at release: exclusivity may have lapsed while the
      callback ran`, with the lock in its context: the session may have ended while the
      callback ran, so check the work it guarded, and keep a locked callback's idle time well
      below `wait_timeout`.
    - On Unix-like systems, MySQL configuration with `database.hostname = 'localhost'` and a
      `database.port` other than 0 or 3306 is rejected when database configuration is first
      read, not necessarily during boot: mysqlnd would use the Unix socket and ignore the
      port. Use `127.0.0.1` for TCP. On Windows, where mysqlnd connects to localhost over TCP
      and uses the port, it is accepted.
    - Float parameters reach the database with every digit, such as `0.30000000000000004`
      instead of `0.3`. Round explicitly, or pass a string for an exact decimal.

### Good to know

1. `tools/migrate.php <release> <shared> <tag>` applies a release's `migrations/*.sql` once
   each, in file-name order, after an owner-only dump of the database. It needs the
   `mariadb`, `mariadb-dump` and `gzip` binaries, supports MariaDB only, reads the database
   only from the release's own local config file, and creates its `deploy_migration` table
   on its first run with a migration to apply. A site without a `migrations/` directory is
   unaffected. It is not vendored with `core/` and `extensions/`: to use it, copy it into your
   project's `tools/`, and again on every upgrade. Before its first run on a database whose
   migrations were applied by hand, record them as applied, or they run again. See
   [Database: Deploy migrations](docs/07-Database.md#deploy-migrations).
2. Template and asset-version cache entries are now keyed by their file path. Entries
   written under the old keys are no longer read and expire within their 60-second TTL; no
   call site changes.
3. A cache policy in `extensions.security.headers` - `Cache-Control`, `CDN-Cache-Control`,
   `Cloudflare-CDN-Cache-Control`, `Surrogate-Control`, `Edge-Control`, `X-Accel-Expires`
   or `Expires` - is no longer sent on a response bound to one visitor or one request: an
   error other than 404 and 410, a response to a request that used a session or carried
   credentials such as `Authorization`, one that sets a cookie, and an HTML page given a
   CSP nonce. Those get `Cache-Control: no-store`, even when they set a public policy
   themselves; a `no-store` or unqualified `private` they set on the `Response`, with no
   targeted header beside it, stands. Any other response that sets one of those headers
   itself, on the `Response` or with `header()`, now keeps it, where the configured value
   used to replace it. Whatever cache policy is configured, the redirects of `auth` and
   `oidc-login` now send `no-store`, and so does an error page for a visitor with a session,
   in place of the session's own cache headers; with the security extension enabled, so
   does a bound response that set a shareable policy on the `Response` itself. See
   [Extension: security](docs/22-Extensions-Security.md#caching).
4. Whether an extension runs is read on every request, so turning one on or off in the
   local file or an environment variable takes effect without deleting
   `cache/extensions.php`. Adding or removing an extension folder still needs it deleted.
5. `Format::escape()` encodes `{` and `}` as `&#123;` and `&#125;`, as `tokens()` does, so
   text it escaped cannot form a token or block marker in a page; browsers show the same
   characters. `Translation::text()` also encodes a placeholder its stored text leaves
   unfilled.
6. The request body is read when `rawBody()` or `json()` first asks for it, not for every
   request, and `json()` reads it only when `isJson()` is true. `isJson()` matches
   `application/json` and `application/*+json` by media type, so
   `text/plain; x=application/json` and `application/jsonp` no longer count.
7. `Db::lock()` names are keyed on `database.database` instead of the install path, so every
   release directory and server of the application takes the same lock. While the old
   release still runs beside the new one during this upgrade, the two use different names.
8. A `session.lifetime` longer than `session.gc_maxlifetime` raises it for this application's
   own garbage collection, and never lowers a longer server value. In a shared save path
   another collector - another application, or a cron job such as Debian's `sessionclean` -
   can still remove data sooner: give a long lifetime a `save_path` of its own.
9. XML responses - `text/xml`, `application/xml` and every `+xml` type, SVG and XHTML among
   them - get `Content-Security-Policy`, `X-Frame-Options`, `Permissions-Policy` and the
   cross-origin policies, as HTML pages do; their CSP carries no nonce source, since only an
   HTML body has `{_csp_nonce}` filled.
10. A new log file is created under a temporary name and linked into place. Where it cannot
    be linked - a filesystem without hard links - it is created under its name and narrowed
    straight after, as before.
11. `Response::$json` of the HTTP client is decoded on first read; `var_dump()` and
    `get_object_vars()` of a response no longer show it.
12. Without APCu, `Cache::once()` keeps its sentinels in the bundle directory as `once-*`
    files instead of in the system temporary directory, so work it throttles - the daily log
    prune, error-log's purge - runs once more after the upgrade, and again whenever the
    `once-*` files are deleted (`rm -f cache/*.php` leaves them). The `cache-once-*` files
    5.2.0 left in the temporary directory are no
    longer read and can be deleted. Code that calls `Cache::once()` without `Kernel::boot()`,
    such as a standalone CLI script, runs its callback unguarded. If your `.gitignore` ignores
    only `cache/*.php`, add `cache/once-*`, or ignore `cache/` as a whole. Where the web server
    and CLI jobs share `cache/` through a group, both need umask `002`: under `022` only the
    account that created a sentinel can write it, so once it expires the other account runs
    the work on every call until the creator runs it again.
13. The HTTP client sends a bodiless `POST`, `PUT` or `PATCH` with `Content-Length: 0`; it was
    sent chunked (`POST`) or without a length (`PUT`, `PATCH`), which some gateways answer
    with 411 or 400.
14. SMTP: a reply carrying only its code (`250` CRLF) is a final reply, and `QUIT` and closing
    the connection after the server accepted the message are best effort, so an accepted
    message is no longer reported as failed and sent twice by a retry. Connection and TLS
    handshake failures throw `SMTP_CONNECT_FAILED` and `SMTP_TLS_FAILED` rather than an
    `ErrorException`. STARTTLS failures now include a bounded, single-line reason after
    `SMTP_TLS_FAILED: `; replace exact-message comparisons with a prefix check.
15. Each distinct deprecation - same file, line and message - is logged once per request, and
    at most 100 per request. The record lasts for the PHP process, so a CLI script or worker
    logs each one once per run and, after 100 distinct ones, none for the rest of the run.
    `Kernel::boot()` turns off `display_errors` and trace arguments
    before it loads the configuration, so a boot that fails early is not printed.

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
