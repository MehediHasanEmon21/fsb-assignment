# API Documentation

The reviewer-facing OpenAPI specification is stored in:

- `docs/openapi.yaml`

It can be opened in Swagger UI, Redoc, Stoplight, Postman, or any OpenAPI
3.1 compatible viewer.

## Base URL

Local Docker:

```text
http://localhost:8000/api/v1
```

API version:

```text
v1
```

## Authentication

Use `POST /auth/login` to receive a Laravel Sanctum bearer token.

Authenticated requests require:

```http
Authorization: Bearer {token}
Accept: application/json
```

Tenant-scoped requests also require:

```http
X-Tenant-ID: {tenant_id}
```

The route tenant path parameter and `X-Tenant-ID` must identify the same
resolved tenant context. Permissions alone never grant cross-tenant access.

## Seeded Reviewer Accounts

After running `php artisan migrate:fresh --seed`, these local assessment
accounts are available. They all use the password `password`.

| Email | Role | Tenant | Purpose |
| --- | --- | --- | --- |
| `superadmin@example.test` | `super admin` | Platform | List and create tenants |
| `admin@acme.test` | `tenant admin` | Acme Software Ltd | Tenant admin API flow |
| `manager@acme.test` | `manager` | Acme Software Ltd | Limited tenant management |
| `user@acme.test` | `user` | Acme Software Ltd | Read-only tenant access |
| `admin@northwind.test` | `tenant admin` | Northwind Labs | Second tenant isolation checks |
| `manager@northwind.test` | `manager` | Northwind Labs | Limited second-tenant access |
| `user@northwind.test` | `user` | Northwind Labs | Read-only second-tenant access |

Reviewer flow:

1. Log in with `POST /auth/login`.
2. Read `data.user.tenant_id` when the account has exactly one accessible
   tenant, or select an entry from `data.user.tenants` when it has several.
   `GET /auth/me` returns the same access summary.
3. A Super Admin receives `tenant_id: null`, `role: "super admin"`, and selects
   a target using `GET /tenants` because platform access is not tenant
   membership.
4. Use `GET /tenants` when a fresh tenant list is needed or to find a tenant by
   slug such as `acme-software`.
5. Send the selected id as both the route tenant id and `X-Tenant-ID`.
6. Call `/tenants/{tenant}/customers`, `/tenants/{tenant}/subscription`, and
   `/tenants/{tenant}/dashboard`.
7. Reuse the Acme token against a Northwind tenant id to verify cross-tenant
   access is rejected.

Authenticated user access fields:

```json
{
  "tenant_id": 1,
  "role": "tenant admin",
  "is_super_admin": false,
  "tenants": [
    {
      "id": 1,
      "name": "Acme Software Ltd",
      "slug": "acme-software",
      "role": "tenant admin"
    }
  ]
}
```

`tenant_id` is a convenience value only when exactly one active tenant is
accessible. For a multi-tenant user it is `null`; the client must choose from
`tenants` and use the role belonging to that selected entry.

After a fresh seeded database, `acme-software` is normally tenant id `1`,
`northwind-labs` is normally tenant id `2`, the seeded plans are `starter`
and `professional`, and Acme includes seeded customers such as
`avery@acme.test`. The OpenAPI examples use these seeded reviewer values where
an existing value is valid. Create requests use new demo emails because seeded
emails must remain unique.

## Response Shape

Success:

```json
{
  "success": true,
  "message": "Request successful.",
  "data": {}
}
```

Error:

```json
{
  "success": false,
  "message": "This action is unauthorized."
}
```

An unmatched API URL or HTTP route returns `404` using the same envelope:

```json
{
  "success": false,
  "message": "Requested resource not found."
}
```

Validation error:

```json
{
  "success": false,
  "message": "The name field is required.",
  "errors": {
    "name": ["The name field is required."]
  }
}
```

Paginated lists return:

```json
{
  "success": true,
  "message": "Request successful.",
  "data": {
    "items": [],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 0
    }
  }
}
```

## Filtering, Sorting, Pagination

List endpoints support allow-listed query parameters only. Invalid sort
fields, directions, filters, or pagination sizes return `422`.

All query parameters are optional. The default plan-list request is:

```http
GET http://localhost:8000/api/v1/plans
```

In Postman, leave the optional parameters unchecked in the **Params** tab and
enable only the values being tested. In Swagger, leave optional parameter
fields empty. A filter is appended to the URL only when it is enabled and has a
value. For example:

```http
GET http://localhost:8000/api/v1/plans?billing_interval=yearly&sort=price&direction=desc
```

Common pagination:

- `page`: integer, minimum `1`
- `per_page`: integer, `1` to `100`, default `15`

Common sorting:

- `direction`: `asc` or `desc`

Endpoint-specific filters and sort fields are listed in `docs/openapi.yaml`.

## Rate Limits

Login is rate limited by normalized email and IP address. General API routes
are rate limited by token hash when authenticated, falling back to IP address.
The default general API limit is configured by `API_RATE_LIMIT_PER_MINUTE`.

## Endpoint Groups

- Authentication: login, logout, current user, authenticated tenant user creation
- Plans: list and view active plans
- Tenants: list, create, view, update tenants
- Tenant Members: list, status update, role update, remove membership
- Customers: tenant-scoped CRUD with quota enforcement
- Subscriptions: show, assign/change, cancel active subscriptions
- Dashboard: tenant metrics, subscription, and feature usage summary
