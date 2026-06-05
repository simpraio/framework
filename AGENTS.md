# AGENTS.md

Instructions for AI coding agents working in a Simpra project.

These rules are intentionally strict. Simpra is a small framework by design, not a large framework missing features.

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

Use the project `mago.toml` when checking the framework:

```sh
mago lint --minimum-report-level warning
mago analyze --minimum-report-level warning --reporting-format short
```

Do not relax thresholds or add unnecessary comments only to silence tooling.

## Change Discipline

When changing code:

- Keep edits tightly scoped.
- Preserve public behavior unless the task explicitly changes it.
- Do not add compatibility shims for private implementation details.
- Do not refactor unrelated code.
- Do not make production code worse to satisfy a weak test.
- Prefer fixing obsolete docs/tests over bending framework code around them.

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
