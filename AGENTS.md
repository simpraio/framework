# AGENTS.md

Instructions for AI coding agents working in a Simpra project.

These rules are intentionally strict. Simpra is a small framework by design, not a large framework missing features.

## Canonical Template

**This file is the handbook — read it in full.** Its canonical source is
`SIMPRA/framework-develop/AGENTS.md`.

`CLAUDE.md` must never be an independent copy of this file. It is either a one-line `@AGENTS.md`
import (the projects) or a symlink to it (the framework checkouts) — both of which cannot go stale. A
duplicated copy is what the agent actually loads, so it drifts silently and then contradicts the
handbook it was copied from. Anything you would write into `CLAUDE.md` belongs here instead.

Where it is a symlink, **never redirect a shell write at `CLAUDE.md`**: `> CLAUDE.md` follows the link
and truncates this handbook. Write `AGENTS.md` and let the link follow. Both framework checkouts have
already lost their handbook to a one-line `printf` this way and had to be restored from a project copy.

Copy this handbook byte-for-byte into every project built on the framework, so framework and
architecture rules stay aligned across them. Keep product-specific behavior out of it and document
that in the project's `PRODUCT.md`.

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

- Runtime reflection.
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
- PHP and database must use the configured application timezone.
- Database connections must set timezone immediately after connect.
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
The second one is what keeps the vendored framework honest, and it deliberately uses **no** baseline:
with `mago.toml`'s `[linter.rules]` block matching the framework's own settings, `core/` and
`extensions/` report nothing at all, so a finding there means either the rule config drifted from the
framework's or vendored code was edited in place. Fix that cause rather than baselining the symptom.
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
