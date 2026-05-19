# Contributing

Thanks for considering a contribution.

Simpra is intentionally small. Contributions are welcome when they preserve that shape: explicit code, minimal runtime overhead, optional features, and no hidden magic.

## Design Rules

- Keep core minimal and application-agnostic.
- Prefer explicit code over clever abstractions.
- Prefer simple composition over framework-style indirection.
- Do not add reflection, auto-wiring, service providers, annotations, or attribute-based wiring.
- Do not add interfaces unless there are realistic multiple implementations.
- Do not add compatibility layers only to preserve an internal detail.
- Do not move application behavior into `core/`.
- Keep extensions optional and easy to disable.

## Architecture Trade-Off

Simpra accepts some coupling on purpose.

This framework is not trying to model a large enterprise architecture. It is trying to make small projects fast to build, easy to read, and cheap to run. A change that looks more "architectural" but adds indirection, runtime work, or harder debugging is probably not a good fit.

## Good Contributions

Good changes usually look like this:

- Fix a real bug.
- Clarify documentation.
- Improve security without adding surprise behavior.
- Remove stale or unused code.
- Simplify an implementation.
- Improve performance without making the code harder to read.
- Add an optional extension that follows the existing hook/contributor model.

## Changes That Usually Do Not Fit

These are likely to be declined:

- Dependency injection containers.
- Auto-discovery.
- Service providers.
- Attribute or annotation routing.
- Runtime reflection.
- Enterprise layering for its own sake.
- Broad rewrites without measurable benefit.
- New dependencies that only save a small amount of code.

## Configuration

Committed defaults belong in `config/*.php`.

Environment-specific values and secrets belong in one of:

- `config/framework.local.php`
- `framework.local.php`
- `SIMPRA_*` environment variables

Do not commit real credentials.

## Static Checks

Before opening a pull request, run:

```sh
mago lint --minimum-report-level warning
mago analyze --minimum-report-level warning --reporting-format short
```

The committed `mago.toml` defines the scan scope and rule thresholds.

## Documentation

Documentation paths should be written from the application root, meaning the contents of the `framework/` folder after a user copies them into a project.

Use paths like:

```text
public/
config/
modules/
templates/
cache/
logs/
```

Avoid user-facing docs that describe runtime paths as `framework/public` or `framework/config`, except when explicitly explaining how to run directly from this repository checkout.

## Review Standard

A contribution should answer these questions:

- What behavior changes?
- Why is the change needed?
- What risk does it reduce?
- What runtime cost does it add?
- Does it keep the framework small and explicit?

If the change mainly makes the framework look more like a larger framework, it probably goes against the project.
