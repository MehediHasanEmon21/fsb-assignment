# Project Instructions

## Purpose

This repository implements the **SaaS Subscription & Tenant Management
API** backend engineering assessment.

Laravel Boost provides general Laravel- and package-specific AI
development guidance. This file contains the project-specific rules
Codex must preserve.

## Project Sources

The project requirements, implementation sequence, and chosen technical
approach are stored in `assesment/ACTION_PLAN.md`.

Use this priority when there is a conflict:

1.  `assesment/ACTION_PLAN.md` --- project requirements, implementation
    sequence, and chosen technical approach.
2.  Existing implemented code and tests --- current project state.
3.  Laravel Boost guidance --- Laravel/package conventions and
    version-aware guidance.

Do not invent requirements that are not supported by the action plan or
current project behavior.

## Laravel Boost

Laravel Boost is installed during **Phase 1**, after Laravel and the
Docker application container are available.

For a fresh checkout or a newly scaffolded environment, first verify PHP,
Composer, Docker, and Docker Compose are available. This project should
run through Docker, not `php artisan serve`.

The Phase 1 action plan explicitly includes:

```bash
docker compose exec app composer require laravel/boost --dev
docker compose exec app php artisan boost:install
```

If Boost is already installed, do not reinstall it unnecessarily. Verify
it with:

```bash
docker compose exec app composer show laravel/boost
docker compose exec app php artisan list boost
```

Boost-generated guidance and integration files may include `CLAUDE.md`,
`boost.json`, `.mcp.json`, and agent skill directories. Treat those as
Laravel/framework guidance only. This `AGENTS.md` remains the
project-specific source of truth for assessment scope, phase boundaries,
tenancy rules, and reporting.

After Boost is installed, use its Laravel-aware capabilities when useful
for:

-   Laravel/package documentation
-   application inspection
-   routes
-   configuration
-   database/schema inspection
-   logs
-   tests
-   framework-aware development guidance

Do not duplicate generic Laravel guidance that Boost already provides.

Boost does not replace `assesment/ACTION_PLAN.md`, project-specific
architecture decisions, or phase boundaries.

## Phase-Based Development

The project is implemented phase by phase from `assesment/ACTION_PLAN.md`.

When asked to implement a phase:

1.  Locate and read the requested phase.
2.  Review the existing implementation and completed earlier phases.
3.  Use Laravel Boost guidance/tools when available and relevant.
4.  Implement only the requested phase.
5.  Run the relevant tests and verification commands.
6.  Fix issues introduced by the requested phase.
7.  Report the result.
8.  Stop.

Do not automatically implement the next phase.

Do not prematurely implement features assigned to later phases unless a
minimal prerequisite is strictly necessary for the current phase.
Explain any such prerequisite.

## Required Stack

The planned stack is:

-   Laravel 13
-   REST API
-   Laravel Sanctum
-   Spatie Laravel Permission
-   MySQL 8+
-   Redis
-   Docker / Docker Compose
-   Nginx
-   PHP-FPM
-   Laravel Boost for AI-assisted development

Use packages/features in the phase where `assesment/ACTION_PLAN.md`
introduces them, except foundational dependencies explicitly required by
the current phase.

## Multi-Tenancy

The chosen architecture is:

**Single database + shared schema + tenant isolation.**

Never trust a client-provided tenant identifier without validating the
authenticated user's access.

Tenant-owned data must remain isolated across queries, authorization,
validation, caching, queues, and analytics.

Tenant context must be scoped to the current request or job. Never keep
mutable tenant state in process-global or static storage that can leak
between PHP-FPM requests or long-running queue jobs.

Permission alone must never grant cross-tenant access.

## Engineering Scope

Keep the implementation production-oriented, maintainable, testable, and
understandable.

Do not add architectural layers merely to make the project look complex.

Use abstractions only when they solve a concrete problem. Repositories
are not mandatory and should be introduced only when justified.

Avoid unrelated refactoring, unnecessary packages, speculative
requirements, and premature implementation of future phases.

## Application Architecture

Keep controllers focused on HTTP concerns: accept validated input, call
a focused service, and format the response. Business logic, database
state changes, and authentication operations belong in services.

Apply SOLID principles through cohesive responsibilities, dependency
injection, and clear boundaries. Prefer Laravel's native abstractions
and established project patterns. Introduce a design pattern only when
it solves a concrete variation, lifecycle, or maintainability problem;
do not add patterns merely for appearance.

Services should receive validated values, models, or focused data
objects rather than depending directly on the HTTP request. Use database
transactions in the service layer when a business operation changes
multiple dependent records or requires atomicity.

Service methods should throw meaningful exceptions when an operation
cannot be completed. Controller actions that invoke services must use
`try`/`catch` blocks to translate expected exceptions into appropriate
API errors. Unexpected exceptions must be reported internally and
returned as generic server errors; never expose raw exception messages,
queries, credentials, or stack details to API clients.

Use the shared API response trait for controller success and error
responses so response envelopes remain consistent.

Repositories are optional. Introduce one only when it provides a real
boundary for complex, repeated, or interchangeable persistence logic.
Do not wrap straightforward Eloquent queries merely to satisfy a
repository pattern.

User registration is not public. Creating a user requires an
authenticated request. Permission-level restrictions on user creation
must be added in the authorization phase; authentication alone is not
the final authorization policy.

## API Design & Security

Keep API routes versioned. Use Form Requests for non-trivial input
validation and API Resources or explicit allow-lists for output. Never
serialize models blindly when that could expose sensitive or internal
fields.

Keep response envelopes and HTTP status codes consistent. Support
filtering, sorting, and pagination only through explicit validated
parameters. Allow-list client-selected query fields and directions.

Authentication, authorization, and tenant membership are separate
checks. Every protected operation must enforce all applicable checks in
the correct order. A valid role or permission never bypasses tenant
ownership, and a valid tenant membership never grants an unassigned
capability.

Prevent IDOR by resolving tenant-owned resources through the active
tenant boundary before applying policy or permission checks. Do not
reveal whether an inaccessible cross-tenant resource exists.

Apply deliberate rate limits to login, credential recovery, and other
sensitive or abuse-prone endpoints. Choose limiter keys that balance
account protection with clients sharing an IP address.

Use validated input for writes and keep mass-assignment allow-lists
narrow. Report unexpected exceptions internally and return generic API
errors without stack traces, SQL, credentials, tokens, or internal
implementation details.

## Database

Database design must follow the actual SaaS business requirements.

Use foreign keys, unique constraints, indexes, and transactions
deliberately.

Do not add speculative fields or indexes without a clear use case.

## Performance & Query Design

Design queries for tenant-scoped access from the beginning. Avoid N+1
queries with deliberate eager loading, use database aggregates instead
of loading full collections for counts or sums, and paginate potentially
large result sets.

Tie indexes to demonstrated query shapes, including filter and ordering
columns in the correct sequence. Avoid redundant indexes. Use query
inspection and `EXPLAIN` for important paths during the optimization
phase, and document the query and reasoning behind significant indexes.

Do not optimize speculatively or hide inefficient access behind an
abstraction. Prefer measurable, explainable changes that preserve tenant
isolation and API behavior.

## Redis Caching

Introduce application-level caching only in the phase assigned by the
action plan and only for responses or domain data with clear reuse and
cost benefits.

Every tenant-owned cache key must include the tenant identifier. For
each cached resource, define what is cached, why it is cached, its TTL,
and every invalidation trigger. Invalidate explicitly when underlying
tenant data changes; TTL alone is not a correctness strategy.

Never share authorization-sensitive or tenant-owned cached data across
tenants. Tests must cover cache hits, invalidation, and tenant
separation.

## Queues & Background Work

Use background jobs only for work that is genuinely asynchronous or
expensive enough to justify a queue. Immediate consistency requirements
belong in the request transaction.

Queued jobs that operate on tenant data must carry an explicit tenant
identifier and re-establish a validated tenant boundary when running.
Jobs must be retry-safe and idempotent where duplicate execution could
cause harm. Configure retries, backoff, timeout, and failed-job behavior
deliberately.

Dispatch after commit when a job depends on database state created or
changed by the transaction. Tests must cover important job behavior and
tenant context; verification must include the Redis-backed Docker queue
worker.

## Environment

Use `.env` as the actual local runtime configuration.

Use `.env.example` only as the committed safe reference/template for
required environment variables.

Never commit `.env`, credentials, tokens, or secrets.

## Testing

Add relevant automated tests as behavior is introduced.

Prioritize tenant isolation, authentication, authorization, business
rules, validation, API behavior, subscription/feature limits, cache
invalidation, and queue behavior.

Do not create meaningless tests only to increase coverage.

## Assessment Readiness

The assessment allows AI-assisted development, but the implementation
must remain understandable and explainable.

Prefer technical decisions that can be clearly defended during a
technical interview.

Record significant architecture, caching, invalidation, indexing,
authorization, and queue decisions as their phases are implemented.
Documentation must explain the chosen approach, relevant alternatives,
and trade-offs, and must stay consistent with the actual code and API
behavior.

## Phase Completion Report

After implementing a phase, report:

### Implemented

What was completed.

### Important Files

Important files created or changed.

### Technical Decisions

Important decisions and reasons.

### Database Changes

Migrations, relationships, constraints, and indexes when applicable.

### Security / Tenancy

Relevant security and isolation decisions.

### Tests

Tests executed and results.

### Verification

Commands needed to verify the phase.

### Assumptions / Trade-offs

Important assumptions or limitations.

### Next Phase

Name the next phase only.

Then stop.
