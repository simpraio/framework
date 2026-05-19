# Philosophy

> This is not missing architecture. This is deliberately smaller architecture.

Simpra is built for small websites, internal tools, and simple SaaS projects where developer speed, predictable behavior, and low runtime overhead matter more than framework extensibility.

It does not try to compete with Laravel, Symfony, or other large general-purpose frameworks. Those tools are better choices when you need large teams, package ecosystems, auto-wiring, service providers, advanced dependency graphs, or enterprise architecture patterns.

Simpra makes a different trade-off: fewer layers, fewer concepts, and fewer places where behavior can hide.

## Why It Is Small

The framework is designed so one developer can understand the full request path without reading hundreds of pages of framework internals.

That means:

- Manual bootstrap instead of service providers.
- Explicit config files instead of discovery.
- Convention routing instead of attributes.
- Static facades for ergonomics, backed by simple service instances.
- Optional extensions instead of a mandatory feature stack.
- No reflection or auto-wiring in the runtime path.

This is not because dependency injection, interfaces, or richer architecture are bad. They are useful tools. They are just not free. In a small framework, every abstraction has to earn its place.

## Intentional Coupling

Simpra accepts some coupling where it makes the system simpler, faster, and easier to read.

For example, the framework has a small number of core services wired during bootstrap. Public facades call those services directly. That is more coupled than a full dependency injection container, but it is also easier to follow and cheaper to run.

The rule is practical:

- Coupling is acceptable when it keeps behavior explicit and local.
- Abstraction is welcome when it removes real complexity.
- Indirection is avoided when it only makes the code look more architectural.

The goal is not theoretical purity. The goal is a framework that can be understood, changed, and deployed quickly.

## What Belongs In Core

Core should stay framework-level and application-agnostic.

Good core responsibilities:

- Bootstrapping.
- Configuration loading.
- Routing.
- Request and response handling.
- Sessions.
- Templates and layout composition.
- Logging.
- Database access helpers.
- Extension loading.

Application-specific behavior belongs in `app/`, `modules/`, or `templates/`. Optional behavior belongs in `extensions/`.

## Extensions

Extensions are intentionally simple. They add behavior around requests, responses, layout composition, or application helpers without becoming a plugin ecosystem.

An extension should be easy to disable. A basic page should not need a database, APCu, mail transport, auth system, or SEO table unless the project actually enables those features.

This keeps the base framework small and lets applications pay only for the behavior they use.

## What Simpra Is Good For

Use Simpra when you want:

- A small website.
- A landing page or presentation site.
- An internal tool.
- A simple SaaS project.
- A framework you can read and modify directly.
- Fast warm requests with little runtime machinery.
- Predictable code over hidden magic.

## When Not To Use It

Do not use Simpra when you need:

- A large package ecosystem.
- Enterprise architecture patterns.
- Attribute-based dependency injection.
- Auto-discovery or auto-wiring.
- Large-team module boundaries.
- Complex domain modeling.
- Long-lived applications with many independent teams.

Those are valid needs. They are just outside this framework's purpose.

## Contribution Principle

The best contribution keeps the framework smaller, clearer, safer, or faster without changing its character.

Before adding a layer, ask:

- Does this remove real complexity?
- Does this make the common path easier to understand?
- Does this reduce runtime work?
- Does this preserve explicit behavior?
- Would a new user understand this faster than the previous code?

If the answer is no, the simpler code is usually better.
