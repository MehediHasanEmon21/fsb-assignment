# SaaS Subscription & Tenant Management API

This is a Laravel backend for a SaaS-style subscription and tenant
management system. It is built as a REST API, with tenant isolation,
subscription plans, feature limits, role-based access, Redis caching,
queue-backed email work, and automated tests around the important business
rules.

The code is intentionally direct. Controllers handle HTTP concerns,
services handle business operations, policies and permissions handle access,
and tenant-owned data is always resolved through the selected tenant context.

## What It Does

- Authenticates API users with Laravel Sanctum bearer tokens.
- Supports multiple tenants in one shared database.
- Lets a platform super admin create tenants.
- Automatically creates the first tenant admin when a tenant is created.
- Manages tenant users, roles, and membership status.
- Lists subscription plans and assigns/cancels tenant subscriptions.
- Enforces feature limits for users and customers.
- Manages tenant customers with filtering, sorting, and pagination.
- Provides a tenant dashboard with counts, subscription data, and feature usage.
- Uses Redis for cache and queue work.
- Sends tenant admin welcome email work through a queued job.

Public registration is not open. A user is created by an authenticated tenant
user with the right permission.

## Technology Stack

- Laravel 13
- PHP 8.4 FPM
- Laravel Sanctum
- Spatie Laravel Permission
- MySQL 8.4
- Redis 8
- Docker / Docker Compose
- Nginx
- PHPUnit
- Laravel Pint
- Laravel Boost for framework-aware development guidance

## Setup Instruction

From a clean clone:

```bash
git clone https://github.com/MehediHasanEmon21/fsb-assignment.git
cd fsb-assignment
cp .env.example .env
docker compose build
docker compose up -d
```

Install PHP dependencies inside the application container:

```bash
docker compose exec app composer install
```

Generate the application key:

```bash
docker compose exec app php artisan key:generate
```

Run migrations and seeders:

```bash
docker compose exec app php artisan migrate --seed
```

The API is available at:

```text
http://localhost:8000/api/v1
```

Opening `http://localhost:8000` returns a small JSON service-information
response. This is an API-only project, so the default Laravel welcome page is
not exposed. Laravel's health check remains available at
`http://localhost:8000/up`.

Unknown web or API routes return a JSON `404` response with the message
`Requested resource not found.`

The MySQL container is exposed on the host at port `3307`. Inside Docker,
Laravel connects to MySQL through the `mysql` service on port `3306`.

## Useful Commands

Run the full test suite:

```bash
docker compose exec app php artisan test
```

Check code style:

```bash
docker compose exec app ./vendor/bin/pint --test
```

Clear Laravel caches:

```bash
docker compose exec app php artisan optimize:clear
```

Run seeders again:

```bash
docker compose exec app php artisan db:seed
```

Fresh database with seed data:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

## Seeded Reviewer Data

The seeders create local demo data so the API can be exercised immediately
after `migrate:fresh --seed`. These accounts are only for the local assessment
environment and all use the password `password`.

| Email | Role | Tenant | Tenant slug | What to test |
| --- | --- | --- | --- | --- |
| `superadmin@example.test` | `super admin` | Platform | n/a | List and create tenants |
| `admin@acme.test` | `tenant admin` | Acme Software Ltd | `acme-software` | Full tenant administration |
| `manager@acme.test` | `manager` | Acme Software Ltd | `acme-software` | Customer/user management without platform access |
| `user@acme.test` | `user` | Acme Software Ltd | `acme-software` | Read-only tenant operations |
| `admin@northwind.test` | `tenant admin` | Northwind Labs | `northwind-labs` | Separate tenant context |
| `manager@northwind.test` | `manager` | Northwind Labs | `northwind-labs` | Second tenant permission checks |
| `user@northwind.test` | `user` | Northwind Labs | `northwind-labs` | Second tenant read-only checks |

There is also an inactive tenant, `suspended-demo`, for access-denial checks.

Suggested reviewer flow:

1. Log in as `superadmin@example.test` and list tenants.
2. Log in as `admin@acme.test`.
3. Call Acme customer, subscription, and dashboard endpoints with Acme's
   tenant id in `X-Tenant-ID`.
4. Retry a Northwind tenant route with the Acme token to confirm tenant
   isolation.
5. Log in as `admin@northwind.test` and confirm Northwind data is separate.

Inspect API routes:

```bash
docker compose exec app php artisan route:list --path=api/v1
```

The queue worker is already defined as the `queue` service in Docker Compose.
For a foreground worker during debugging:

```bash
docker compose exec app php artisan queue:work redis --queue=notifications,default
```

## API Documentation

API documentation lives in:

- [API documentation](docs/API_DOCUMENTATION.md)
- [OpenAPI specification](docs/openapi.yaml)

Open the [OpenAPI specification](docs/openapi.yaml) in Swagger Editor, Swagger
UI, Redoc, Stoplight, or Postman. The spec documents the base URL,
authentication, tenant header, endpoints, request bodies, filters, sorting,
pagination, status codes, and error responses.

Quick auth flow:

```http
POST /api/v1/auth/login
```

Then send protected requests with:

```http
Authorization: Bearer {token}
Accept: application/json
```

Tenant-scoped routes also require:

```http
X-Tenant-ID: {tenant_id}
```

## Architecture

The application uses a versioned REST API under `/api/v1`.

The main shape is:

- Routes define the HTTP surface and middleware stack.
- Form Requests validate input and allow-list query parameters.
- Controllers stay thin and return the shared API response envelope.
- Services perform business operations and transactions.
- Policies and Spatie permissions enforce capabilities.
- Tenant context ensures tenant-owned queries stay inside one tenant.
- API Resources expose only the fields meant for clients.

More detail is documented in the [architecture notes](docs/ARCHITECTURE.md).

## Multi-Tenancy

The project uses a single database and shared schema. Users are global
identities and can belong to many tenants through the `tenant_user` table.

Tenant-scoped endpoints require a valid `X-Tenant-ID` header. The selected
tenant is resolved against the authenticated user's accessible tenants, except
for super admin platform operations where the super admin can manage tenants
without membership.

Important rule: permission alone is never enough for tenant-owned data. The
request must also resolve to the correct tenant context.

## Authorization

Authentication, membership, and authorization are separate checks:

- Sanctum proves who the user is.
- Tenant resolution proves which tenant the request is operating in.
- Spatie permissions and policies decide what the user may do.

Roles are lowercase and use the `sanctum` guard:

- `super admin`
- `tenant admin`
- `manager`
- `user`

`super admin` can manage the platform, but tenant-scoped routes still need a
valid tenant context where the route requires one.

## Subscriptions and Feature Limits

Plans define feature values through `plan_features`.

Current seeded features:

- `users`: numeric limit
- `customers`: numeric limit
- `analytics`: boolean feature

Subscriptions belong to tenants. The entitlement service reads the tenant's
current active subscription and decides whether a feature is available. For
numeric limits, usage is tracked in `feature_usages` for the subscription
period.

Customer creation and user activation are quota-protected. Over-limit writes
fail without partially creating the next record.

## Database Design

Important tables:

- `users`: global user identity, email is globally unique.
- `tenants`: tenant/company records.
- `tenant_user`: tenant membership and membership status.
- `plans`: public subscription plans.
- `features`: available plan features.
- `plan_features`: feature values per plan.
- `subscriptions`: tenant subscription history.
- `feature_usages`: tenant feature usage per billing period.
- `customers`: tenant-owned customer records.
- Spatie permission tables: roles, permissions, and model assignments.
- Sanctum personal access token table.
- Laravel queue, cache, and failed job tables.

Key constraints:

- Tenant slugs are unique.
- User emails are unique globally.
- A user can only have one membership row per tenant.
- A plan can only define a feature once.
- Customer emails are unique within a tenant when present.
- Feature usage is unique per tenant, feature, and usage period.

## Caching Strategy

Redis is used as the application cache store.

Cached tenant data:

- Dashboard response data.
- Entitlement snapshots.

Cache keys include the tenant id:

```text
tenant:{tenant_id}:dashboard:v1
tenant:{tenant_id}:features:v1
```

Default TTLs:

- Dashboard: `TENANT_DASHBOARD_CACHE_TTL=60`
- Entitlements: `TENANT_ENTITLEMENTS_CACHE_TTL=300`

The app does not rely on TTL alone. Customer changes, subscription changes,
feature usage changes, and membership changes invalidate the relevant tenant
cache entries.

## Database Optimization

The code avoids loading large collections when aggregate counts are enough.
Dashboard metrics use aggregate queries, list endpoints paginate by default,
and API list inputs only accept allow-listed filters and sort fields.

Indexes are tied to real query shapes:

- `tenant_user(tenant_id, status)` for member counts and active membership.
- `tenant_user(user_id, status)` for accessible tenant lookup.
- `subscriptions(tenant_id, status, ends_at)` and
  `subscriptions(tenant_id, status, starts_at)` for current subscription checks.
- `customers(tenant_id, status, created_at)` for filtered customer lists.
- `customers(tenant_id, name, id)` for tenant customer ordering.
- `plans(status, price, id)` for active plan listing by price.

## Security Notes

- `.env` is ignored and should never be committed.
- `APP_DEBUG=false` is the safe default in `.env.example`.
- API errors use a consistent envelope and avoid stack traces.
- Server exceptions are logged internally.
- Login is rate limited by email and IP.
- General API routes are rate limited by token hash when authenticated.
- Protected routes require Sanctum tokens with the `api` ability.
- Inactive users cannot keep using existing tokens.
- Form Requests validate writes and query parameters.
- API Resources avoid exposing passwords, tokens, and internal fields.
- Cross-tenant resource access returns forbidden or not found without exposing
  tenant-owned data.

## Queues

Tenant creation dispatches a welcome email job for the initial tenant admin.
The job:

- Runs after the database transaction commits.
- Is unique per tenant admin for one hour.
- Re-establishes tenant context while running.
- Sends through Laravel Mail, configured as `log` by default.
- Retries up to three times with backoff.
- Logs tenant-safe failure context.

Docker Compose includes a `queue` service that runs:

```bash
php artisan queue:work redis --queue=notifications,default
```

## Technical Decisions

Single database, shared schema:

This keeps the assessment understandable and easy to run while still requiring
real tenant isolation in queries, policies, validation, cache keys, and jobs.

Separate customers from users:

Customers are business records owned by a tenant. Users are login identities.
If a customer later needs login access, the user identity can be created and
linked deliberately instead of mixing customer CRM data with authentication.

No public registration:

The API is for managed SaaS administration. Tenant users are created by
authenticated users with the correct tenant permission.

Services instead of heavy repositories:

Services own business workflows and transactions. Repositories are not added
around simple Eloquent queries because that would add ceremony without making
the code easier to change.

Explicit cache invalidation:

Tenant cache entries include tenant ids and are invalidated on writes. TTL is
only a fallback, not the correctness mechanism.

## Final Reviewer Checklist

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
```

Then inspect:

- [API documentation](docs/API_DOCUMENTATION.md)
- [OpenAPI specification](docs/openapi.yaml)
- [Architecture notes](docs/ARCHITECTURE.md)
