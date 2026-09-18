# API Documentation

The reviewer-facing OpenAPI specification is stored in:

- `docs/openapi.yaml`

It can be opened in Swagger UI, Redoc, Stoplight, Postman, or any OpenAPI
3.1 compatible viewer.

## Base URL

Local Docker:

```text
http://localhost/api/v1
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
