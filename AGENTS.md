# AGENTS.md

Instructions for AI coding agents working in a Simpra project.

These rules are intentionally strict. Simpra is a small framework by design, not a large framework missing features.

## Canonical Template

**This file is the handbook — read it in full.** Its canonical source is
`SIMPRA/framework-develop/AGENTS.md`.

`CLAUDE.md` must never be an independent copy of this file. In every checkout it is the single line
`@AGENTS.md`, which imports this one and therefore cannot go stale. A duplicated copy is what the agent
actually loads, so it drifts silently and then contradicts the handbook it was copied from. Anything you
would write into `CLAUDE.md` belongs here instead.

**Never redirect a shell write at `CLAUDE.md`** — write `AGENTS.md`. Both framework checkouts once kept
`CLAUDE.md` as a symlink to this file, and a one-line `printf >` aimed at the link truncated the handbook
itself; it had to be restored from a project copy. They are plain one-line imports now, but the habit is
the point: `CLAUDE.md` is a pointer, never a destination.

Copy this handbook byte-for-byte into every project built on the framework, so framework and
architecture rules stay aligned across them. Keep product-specific behavior out of it and document
that in the project's `PRODUCT.md`. `tools/verify-vendored.php` compares both this file and the
`CLAUDE.md` pointing at it against the framework's own: a project carrying neither is left alone, and
whichever it carries must match. A copy that has drifted, or a write that landed on the pointer, is
reported there rather than discovered by an agent reading it.

## Product-Specific Facts

**Read `PRODUCT.md` in the application root before working on anything product-specific.** It holds
what this handbook deliberately does not: the product's domain and data model, which extensions it
enables, its deployment and hosts, and its operational rules. This file stays byte-identical across
projects, so anything that would differ between two of them belongs in `PRODUCT.md`, never here — a
single product fact added to this handbook silently breaks the sync for every other project.

The framework repository itself ships no `PRODUCT.md`; it has no product. Its absence means the
checkout is the framework rather than a project built on it.

## Vendored Framework Code

A project does not install the framework — it vendors it, flattened: `framework/core/` becomes
`core/` and `framework/extensions/` becomes `extensions/` in the application root. Those two
directories are therefore **read-only vendor code inside a project**. Fix a framework defect in the
framework repository and re-vendor; never patch `core/` or `extensions/` in place to satisfy a product
requirement, because the next refresh silently reverts it and the copy stops matching every other
project.

Compare against the framework on **git blob content, not files on disk**: a project may pin `eol=lf`
via `.gitattributes` while the framework repository relies on `core.autocrlf`, so identical content
differs byte-for-byte in the working tree. `git show HEAD:<path>` on both sides is the honest
comparison, and `tools/verify-vendored.php` in the framework repository automates it.

## Project Goal

Simpra is a minimal PHP 8.4+ framework for small websites, internal tools, and simple SaaS projects.

Requires the `mbstring` extension. It is not merely convenient for one extension: `core/tools/Format.php`,
used throughout, calls `mb_strtoupper`/`mb_strtolower` directly with no fallback, and
`extensions/mail/Header.php` throws its own `MAIL_MBSTRING_REQUIRED` at first use if it is missing.
There is no `composer.json` to declare it formally; this is the declaration.

Optimize for:

- Simple explicit code.
- Low runtime overhead.
- Fast development.
- Predictable behavior.
- A codebase one developer can understand end to end.

Do not optimize for enterprise extensibility, plugin ecosystems, auto-wiring, or large-team architecture.

## Path Model

Assume this repository has been copied so the application root contains:

```text
app/
cache/
config/
core/
extensions/
logs/
modules/
public/
templates/
```

Use these app-root paths in docs, examples, and generated code.

Only mention `framework/...` paths when explicitly explaining how to run directly from this repository checkout.

## Where To Put Code

Application code belongs in:

- `modules/` for controllers.
- `templates/` for HTML templates and layout fragments.
- `app/` for application services and business logic.
- `public/` for public assets only.
- `config/` for committed defaults.

Optional reusable framework behavior belongs in:

- `extensions/`

Framework internals belong in:

- `core/`

Do not put application-specific logic in `core/`.

## Core Rules

- Keep `core/` minimal and application-agnostic.
- Prefer explicit code over clever code.
- Prefer fewer layers over abstract architecture.
- Prefer small cohesive classes over large general-purpose helpers.
- Add an abstraction only when it removes real complexity.
- Inline logic is acceptable when it is clearer than indirection.
- Static facades are part of the public API and should delegate to simple internal services.
- Extensions must stay optional.

## Code Comments

- Prefer clear names and simple structure over explanatory comments.
- Keep comments short and specific.
- Add comments only when they preserve important context that the code cannot express clearly by itself.
- Good comments explain security constraints, invariants, edge cases, deployment assumptions, data-format rules, or intentional trade-offs.
- Avoid comments that restate what the next line of code already says.
- Remove stale comments when behavior changes.
- Do not add comments only to make code look documented or to silence tooling.

## Forbidden Direction

Do not introduce:

- Runtime reflection. One exception exists: `Kernel::isController()` calls
  `ReflectionClass::isAbstract()`, because PHP has no other way to ask whether a class a URL names
  can be instantiated. It decides only that, never what gets wired or called. Do not add another.
- Auto-wiring.
- Service providers.
- Container bindings.
- Annotation or attribute-based routing.
- Auto-discovery.
- Enterprise layering for its own sake.
- Interfaces without realistic multiple implementations.
- New dependencies unless they clearly reduce risk or complexity.

## Framework Philosophy

Some coupling is intentional.

Simpra accepts direct wiring and simple service access where it makes the request path easier to read and cheaper to run. Do not rewrite code toward a dependency injection container or large-framework architecture unless there is a concrete bug or measurable benefit.

Before adding a pattern, ask:

- Does this make the common path easier to understand?
- Does this remove real duplication or risk?
- Does this reduce runtime work?
- Does this preserve explicit behavior?
- Would a new user understand this faster?

If not, keep the simpler code.

## Configuration

Committed defaults belong in:

```text
config/*.php
```

Local secrets and environment-specific overrides belong in:

```text
config/framework.local.php
framework.local.php
SIMPRA_* environment variables
```

Never commit real credentials.

Keep `framework.local.php` small. Full extension settings belong in `config/*.php`.

## Database And Time Rules

- Database use is optional.
- DB-backed extensions should be disabled unless needed.
- PHP uses the configured application timezone for display and input only.
- Database sessions are pinned to UTC by the framework and are not configurable. Never set the MySQL
  init command through `database.options`; the framework reserves it for that pin.
- Timestamp columns should use `DATETIME(6)`.
- Automatic DB timestamps should use microsecond precision.
- PHP date/time values should use `DateTimeImmutable`.
- Do not rely on PHP or database server default timezone.

## Templates And Controllers

Controllers should stay thin:

- Read request input.
- Call app services.
- Return a template or response.

Business logic belongs in `app/`.

Templates should render data. Do not hide application logic in templates.

### Tokens carry values, blocks carry decisions

A token is a text channel. `tokens()` HTML-escapes every value and encodes `{` and `}` as
entities, so a value can never form markup or another marker. Anything that is not text to
display is in the wrong channel.

A controller passes the value, never the presentation chosen for it: no class name, no
attribute fragment, no state string a selector reads. The template owns every literal that
exists for styling, and the controller answers only whether it applies.

```html
<!-- wrong: the controller picked how a status looks, and named a class to do it -->
<td><span class="pill {STATUS_CLASS}"><i></i>{STATUS}</span></td>

<!-- right: the controller reports facts, the template holds the classes -->
<td><span class="pill {IsStatusOk}pill-ok{-IsStatusOk} {IsStatusWarn}pill-warn{-IsStatusWarn}"><i></i>{STATUS}</span></td>
```

`{STATUS}` is correct in both: it is the value being shown.

Mutually exclusive states take one block each, and the controller answers **every** block in
the set, the false ones included:

```php
$template->blocks([
    'IsStatusOk'   => $status === 'ok',
    'IsStatusWarn' => $status === 'warn',
]);
```

An unanswered block is not read as false - it stays in the output verbatim - so answering
every block is required, not tidy.

In an attribute, put a space between blocks, **outside** the markers, as the example above
does. The space is not a precaution against a forgotten block: blocks carry no separator of
their own, so two adjacent blocks that are both shown run together and neither class applies.
A space inside the markers fails the opposite way - a forgotten block leaves its class
standing alone, and it switches on:

```html
<!-- both true -> "is-selectedis-disabled": neither applies -->
class="row {IsSelected}is-selected{-IsSelected}{IsDisabled}is-disabled{-IsDisabled}"

<!-- IsDisabled forgotten -> is-disabled applies, though nothing said so -->
class="row{IsSelected} is-selected {-IsSelected}{IsDisabled} is-disabled {-IsDisabled}"

<!-- right: every combination applies exactly the blocks answered true -->
class="row {IsSelected}is-selected{-IsSelected} {IsDisabled}is-disabled{-IsDisabled}"
```

`tests/core/template.php` pins each of these against the engine.

That is more verbose than a single class token, and that is the trade: renaming `pill-ok`
stays a template change instead of a search through `app/`.

The rule covers any attribute a selector reads, not only `class`. `data-status="{STATUS}"`
paired with a `[data-status="ok"]` rule puts the same decision back in PHP under another
name, so it is a block too. `rawTokens()` is no exception either - it exists for HTML the
application itself rendered, not for smuggling a class name past the escaping.

Name a block for the fact, not the markup, in `PascalCase`: `IsStatusOk`, `HasRows`,
`CanDelete`. A name like `IsStatusClass` is still "which class?", only with a boolean's
signature.

## Extensions

Extensions should be optional and explicit.

Use extensions for reusable framework-level behavior such as:

- Auth.
- CSRF.
- Security headers.
- SEO metadata.
- Translation.
- Rate limiting.
- Mail.
- Events.

Do not make basic pages depend on database, APCu, mail, auth, or SEO unless the feature is enabled.

## Documentation Rules

Documentation should use application-root paths:

```text
public/
config/
modules/
templates/
cache/
logs/
```

Avoid stale examples that mention removed cache files, SQL dumps, public helper scripts, or private local tooling.

When documenting optional DB-backed features, mention the relevant schema in:

```text
tools/schema/
```

## Static Checks

Use the project `mago.toml` when checking the code:

```sh
mago lint --minimum-report-level warning --fail-on-out-of-sync-baseline
mago lint core extensions --minimum-report-level warning
mago analyze --minimum-report-level warning --reporting-format short --fail-on-out-of-sync-baseline
```

All three exit 0 whether or not the checkout has baselines, so they are the same commands everywhere.
The second one is what keeps the vendored framework honest. It passes no `--baseline` of its own, but do
not read that as running unbaselined: `mago.toml` declares one, so the project's `mago-app.toml` is loaded
anyway — it simply holds **no entries applicable to those paths**. That is the actual invariant. With
`mago.toml`'s `[linter.rules]` block matching the framework's own settings, `core/` and `extensions/`
report nothing at all, so a finding there means either the rule config drifted from the framework's or
vendored code was edited in place. Fix that cause rather than baselining the symptom.
In a framework checkout this command lints nothing (the code is under `framework/`) and reports a
vacuous success — there, the first command is the real gate.

A project's lint baseline is product-owned, named `mago-app.toml`, and holds **accepted aggregate
structural metrics only**: `cyclomatic-complexity`, `kan-defect`, `halstead`, `too-many-methods`,
`excessive-parameter-list`, `too-many-properties`. It must never hold a style or security rule — a
suppressed `no-literal-password` is a hidden security finding, not an accepted trade-off — and it must
never hold a `core/` or `extensions/` entry, because a project may not except vendored code from a
rule the framework itself passes. The analyzer baseline (`mago-analyze-baseline.toml`) is separate and
may carry reviewed type debt, which is a different judgement from a lint exception.

Three traps, each of which has already cost real time. `--generate-baseline` **ignores**
`--minimum-report-level` and writes back every finding it can see, style and security rules included,
so read the codes in the diff after regenerating rather than trusting the flag. A baseline mago cannot
read is skipped while the command still exits 0, so a wrong `--baseline` path reads as a clean run —
check the path, not just the exit code. And an empty baseline cannot be a zero-byte file: mago rejects
that with `missing field`, so the minimum is a `variant` line and an empty `issues` list.

Do not relax thresholds or add unnecessary comments only to silence tooling.

## Testing Contract

When a test must exist, and what a change has to be covered by. Where the file then goes is
the next section.

```sh
php tests/run_all.php                 # the generic suite: core/, extensions/, integration/
php tests/core/router.php             # one suite
mago lint --minimum-report-level warning
mago analyze --minimum-report-level warning --reporting-format short

# Outside the default run, each needing something this repo does not control
php tests/live/http-client.php        # httpbin.org
php tests/live/mail.php               # a real SMTP relay
php tests/live/oidc-login.php         # Microsoft and Google endpoints; no credentials needed
php tests/http_smoke.php              # the bundled demo app
php tests/security_probe.php --base-url=https://your-app.example --csrf-path=/login --strict
```

`run_all.php` exits non-zero on any failure and names what it did not run. A suite that
skipped everything is reported as a skip, never as a pass.

### Risk tiers

Classify before editing, and state the tier in the commit message.

| Tier | Scope |
|---|---|
| **L0** | Docs, comments, `CHANGELOG.md`, `UPGRADING.md`. No behaviour change. |
| **L1** | Implementation under `core/` or an extension, with no change to any public static method's name, signature, return shape or thrown type. |
| **L2** | Observable behaviour: routing, request parsing, template output, an extension's hook contributions, config keys, enabled/disabled semantics. |
| **L3** | The public facades in `core/*.php`, session, auth, CSRF, egress, the error handler, config precedence, DB schema or connections, anything in `tools/verify-vendored.php`'s sync map, and any change needing an `UPGRADING.md` entry. |

A change needing a line in `UPGRADING.md` is L3 by definition: each one is manual work for
every downstream project.

### R1 — Failing test first

Write the test, watch it fail **on its assertion** rather than on a missing `require`, then
implement. L0 and L1 may skip this if the commit message says why.

Break the code once against a new assertion and confirm the assertion is what fails. Two
suites in this repository have passed while testing nothing: a stubbed error response with
an empty body, where a leak through the other field would have gone unseen, and an
assertion that died as a fatal instead of reporting a `[FAIL]`.

### R2 — Test at the facade, not only the inner class

A suite exercising `core\http\Router` proves the router works. It does not prove
`core\Request` or `core\Kernel` still reach it.

L2 and L3 need at least one suite going through the public static facade application code
actually calls: `Bundle`, `Container`, `Controller`, `ErrorHandler`, `Extensions`,
`Instance`, `Kernel`, `Log`, `Paths`, `Request`, `Result`, `Session`, `Template`, `View`.
The facades are the contract; everything behind them is free to change, so a test pinned to
an inner class constrains the wrong thing and misses the only layer a project can observe.
Inner-class suites stay valuable for branching and edge cases — they just do not satisfy R2
alone.

### R3 — Test the rejection

Every input with a constrained domain needs a suite supplying an invalid value and asserting
the refusal. `tests/core/router.php` is the model: `/foo_bar`, `/foo!bad`, `/foo/../etc` and
`/foo/bar/baz/extra` all map to `NULL` beside the paths that resolve.

On an L3 security surface — CSRF, session, auth, egress, the Host guard — the rejection case
is mandatory, and it must assert that **nothing was persisted or emitted**, not merely that
a falsy value came back.

### R4 — Pin the public surface before changing it

Projects vendor this framework flattened and `tools/verify-vendored.php` compares blob
hashes. That answers "did the copy change?", never "did the contract break?" — a renamed
method, a reordered parameter, a narrowed return type or a new thrown exception all look
like ordinary drift, and surface at the next sync as a runtime failure.

So for any L3 change to a `core/*.php` facade: assert the **old** signature before changing
it, so the break is a failing test rather than a diff; carry the `UPGRADING.md` entry in the
same commit; and name the old and new call shape there, not just the behaviour. Calling each
public static method with minimal arguments and asserting the return shape is enough.

### R5 — Every bug fix starts with a reproduction

No fix lands without a suite that fails before it and passes after.

### R6 — Tests never bend the framework

Every line under `framework/` exists for production. A test adapts to the framework; the
framework never changes shape so a test can reach it. Forbidden, whatever the convenience:

- a parameter only tests pass, such as an injectable clock or a "for testing" flag;
- `public` visibility on a method, property or constant only tests use from outside its class;
- an environment variable, config key or file location only a test setup needs;
- a branch that detects a test, CLI-under-test or fixture environment;
- an interface, factory or indirection whose only second implementation is a test double.

What tests use instead: reflection for internals (`ReflectionClassConstant`,
`ReflectionProperty`, `configureFramework()`); a namespace-local function stub for a global
the framework calls unqualified - `time()` in `tests/extensions/oidc_edges.php`,
`curl_setopt_array()` in `tests/extensions/http-client-curl-setopt.php`; fixtures and local
servers from `tests/helpers.php`; and a temporary copy of the framework base where a server
must find a file the real layout puts elsewhere, as `startFrameworkTestServer()` does. If
none of these reaches the behaviour, the test is at the wrong layer - go through the facade
(R2).

A test asserts the expected value literally, not the framework's own constant: comparing a
URL with the constant that produced it cannot catch the constant being wrong.

To check: a public member that nothing outside `tests/` refers to, and that no document
describes as API, is a violation until shown otherwise. Fix the test, not the framework.

### R7 — Enforcement

Today every rule above is convention and review: there is no CI in this checkout, so nothing
machine-checks them. Anything that can be machine-checked should be.

## Test Layout Contract

The generic suite is **shared**, byte-identical, and vendored into every project exactly like `core/`
and `extensions/`. What belongs where follows from ownership, and nothing else:

**Every suite lives in a discovered group directory. The top level holds only infrastructure.** There is
no per-suite special case in the runner, and nothing at the top level is a test:

| Path | Owner | Runs how |
|---|---|---|
| `tests/core/*.php`, `tests/extensions/*.php`, `tests/integration/*.php` | framework — synced verbatim | subprocess per file, discovered by `tests/run_all.php` |
| `tests/root.php`, `tests/helpers.php`, `tests/run_all.php` | framework — synced verbatim | shared infrastructure and the generic runner |
| `tests/app/*.php` | the project | in-process, via the project's `tests/run.php` |
| `tests/wsl/**` | the project | the project's own DB-backed tier |
| `tests/bootstrap.php`, `tests/run.php` | the project | its harness and entry point; absent from the framework |
| `tests/live/*.php` | framework | needs infrastructure this repo does not control; explicitly invoked |
| `tests/http_smoke.php` | framework | asserts on the framework's own demo app; explicitly invoked |
| `tests/security_probe.php` | framework | deployment-agnostic: point it at any URL with `--base-url`, no copy needed |

**A project's own suites never sit in `tests/` itself.** That directory holds only shared files plus the
project's two entry points, so a product suite dropped there would be compared against the framework and
reported as drift — and the project's runner globs `tests/app/`, so it would not even run.

The top level is where this drifted before, so it is worth stating what each rule cost: `battery.php`
lived there and had to be named explicitly in the runner, and `clear_cache.php` sat among the tests while
asserting nothing (it is now `tools/clear-cache.php`).

The two probes stay in the framework, but for **different reasons, and only one of them is a limitation**.
`http_smoke.php` asserts `200` on `/` and that the body contains the framework demo app's own text, so it
could only ever describe this repository — a project would have to rewrite every assertion, which makes it
the framework's smoke test rather than shared coverage. `security_probe.php` is the opposite: it is
black-box by design and takes `--base-url` / `--host` / `--strict`, so **a project does not need a copy of
it — point the framework's at the project's deployment**:

```sh
php tests/security_probe.php --base-url=https://your-app.example --host=your-app.example \
    --csrf-path=/login --strict
```

That is strictly better than vendoring it, because one canonical probe cannot drift from another copy. It
checks security headers, debug and stack-trace leakage, traversal and sensitive-path exposure, Host-guard
enforcement and cache-clear exposure — all properties of a deployment rather than of a codebase.

`--csrf-path` matters on a deny-by-default app: an unauthenticated POST to `/` is turned away before any
route sees it, so CSRF cannot be observed there and the probe says so rather than implying it is disabled.
Point it at a public form route — the sign-in route — and it becomes a real assertion.

Rules that follow, each of which has already been violated once:

- **Never hardcode the layout.** `tests/root.php` defines `SIMPRA_ROOT` (the directory holding `core/`
  and `extensions/`) and `SIMPRA_LOCAL` (the local config, at the checkout root here and at
  `config/framework.local.php` in a project). Writing `framework/` into a test makes it framework-only,
  which is exactly why the suite could not be shared for as long as it could not.
- **Prove portability in a flattened copy, never in a framework checkout.** Green here proves nothing:
  copy `framework/.` plus `tests/` into a scratch directory with the local config at `config/` only, and
  run the whole suite there. Two of the three hardcoded idioms were invisible until someone did.
- **Suites are discovered, never listed.** A hardcoded list drifted once and silently dropped
  `core/bundle_dir_override`, which existed and passed and never ran.
- **A generic suite must SKIP, not fail, when the environment legitimately lacks what it needs.** A
  project points at a real database with a least-privilege user, so DDL may be denied and a framework
  table may not exist. Print `[SKIP] <reason>` and `exit(0)`: the runner treats a skip-with-no-assertions
  as a skip rather than a pass, so coverage is not silently claimed. `skipWithoutDdl()` in
  `tests/helpers.php` is the shared guard.
- **`tests/live/` is about ownership of the dependency, not about being "integration".** A suite that
  needs *your own* database belongs in `tests/core/` or `tests/extensions/` with a skip guard — that is
  where the six DB-backed suites live, which is also why this repository needs no separate database tier
  at all. `tests/live/` is only for suites depending on infrastructure nobody here controls: today
  `http-client.php` (httpbin.org) and `mail.php`/`mail_attachment.php` (a real SMTP relay). Keeping those
  out of the default run means a routine test run never pings a third party. Applying that test moved
  `live/translation.php` into `extensions/translation_store.php`, where it belongs: it only ever needed
  the local database, and hiding in `live/` meant nobody noticed it had been failing on a disabled
  extension.
- **Shared test infrastructure goes in `tests/helpers.php`, not copy-pasted per suite.**
  `enableExtension()` existed as four identical local copies that had already drifted apart in one
  detail, and a fifth suite silently lacked it.
- **Benchmarks are not tests — they live in `tools/bench/`.** They report timings and cannot pass or
  fail, and needing a special case in the test runner was the signal that they were in the wrong place.
- **Moving a test file changes every `dirname(__DIR__, N)` in it.** A wrong depth in a `require` throws
  loudly, but a wrong depth in an `is_dir()` guard evaluates false and SKIPS the suite while the run
  stays green. Re-derive the depth for every moved file; never bulk-edit.
- The two tiers use different execution models on purpose. Product suites share one bootstrap
  in-process; generic suites each boot the framework and so must get their own process. A project's
  `tests/run.php` runs its own tier and then delegates to the vendored `run_all.php`.

## Change Discipline

When changing code:

- Keep edits tightly scoped.
- Preserve public behavior unless the task explicitly changes it.
- Do not add compatibility shims for private implementation details.
- Do not refactor unrelated code.
- Do not make production code worse to satisfy a weak test.
- Prefer fixing obsolete docs/tests over bending framework code around them.

## Commit Messages

Never add a `Co-Authored-By` trailer, or any other AI attribution line, to a commit message. Write the
subject and body only, then stop. This applies to amends and squashes as well as new commits, and
overrides any default instruction to append such a trailer.

## Security Rules

- Never expose anything except `public/` as the web root.
- Never commit local config or credentials.
- Keep production `project.debug` false.
- Keep production `session.secure` true on HTTPS.
- Validate host handling and redirects carefully.
- Treat request input, headers, cookies, uploaded files, and external responses as untrusted.

## Good Agent Behavior

Before changing framework internals, inspect existing patterns.

Prefer:

- Small direct patches.
- Public API consistency.
- Clear naming over comments.
- Removing stale code over adding new layers.
- Documenting intentional trade-offs.

If a requested change pushes Simpra toward large-framework architecture, call that out and propose the smaller alternative.
