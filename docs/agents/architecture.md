# Architecture

## Behind the gateway

`tds-gateway-api` strips the `/customer` prefix and forwards to this service, so
`…/customer/admin/projects` reaches this app's `/admin/projects`. Routes mount at root.

Build model: a push to `main` assembles the **`dev`** bundle (not deployed). The manual
**Release** workflow (`release.yml`) assembles the **`release`** bundle, pings the deploy webhook and
fires `repository_dispatch(api-pushed)` to the gateway (needs `GATEWAY_DISPATCH_TOKEN`).

## Auth

- `JwksAuthMiddleware` fetches and caches the JWKS from `tds-auth-api` and verifies every Bearer JWT.
  Claims are attached as `request.getAttribute('claims')`. It depends on the one-method
  `Service\TokenVerifier` interface (`JwksClient` implements it), so it is unit-testable.
- `Stripe\WebhookAction` is the **only** route outside that middleware; Stripe authenticates via the
  signature header (`Webhook::constructEvent()`).
- All actions extend `BaseAction` (json helpers, `customerId()`).

## Admin endpoints

Gated by a **per-admin JWT** (`JwksAuthMiddleware(requireAdmin: true)` requires `admin=true`). The
shared `ADMIN_TOKEN` survives only as the `SERVICE_TOKEN` fallback for one server-to-server call.

- `POST /admin/customers` — creates a company `{name, email, createLogin?}`. With `createLogin`
  true or omitted it also provisions an owner login: the insert runs in a transaction and calls
  `tds-auth-api` `POST /admin/customer-credentials` (Bearer `SERVICE_TOKEN`), rolling back on failure.
  With `createLogin: false` it creates only the company; more accounts come from `tds-auth-api`
  `POST /admin/users`.
- `GET /admin/customers` — company list for the admin user management (still a fallback consumer).
- `GET /admin/projects` — flat project list with customer and milestones, for the time-tracking picker.
- `/admin/time-entries/*` — CRUD plus `/timer`, `/timer/start`, `/timer/stop`.
- `/admin/tickets/*`, `/admin/settings` — see [tickets.md](tickets.md), [settings.md](settings.md).

## Portal permissions

Each portal route is additionally gated by `RequirePermissionMiddleware`: `projects:read`,
`invoices:read` / `invoices:pay`, `documents:read` / `documents:write` / `documents:sign`,
`messages:read` / `messages:write`, `tickets:read` / `tickets:write` (mirrors tds-shared's
`PORTAL_PERMISSIONS`). The permission comes from the JWT; admins bypass; missing → 403. Changes take
effect on the next login (auth-api revokes sessions on change).

## Active company: the `X-Act-As-Customer` header

`BaseAction::customerId()` resolves the effective customer through `Support\ActiveCompany` (also used
by `RequirePermissionMiddleware`):

- **Non-admin (multi-company):** the header's id if the login belongs to that company (JWT `companies`
  claim), else the primary company. A non-member id is ignored (no escalation). **Permissions are per
  active company.**
- **Admin:** the header's id for any customer, else the admin's own linked `customer_id`, else **400**
  ("No customer selected"). Admins bypass the permission check.
- Tokens without a `companies` claim fall back to the flat `customer_id` / `permissions` claims.
- `CorsMiddleware` allowlists `X-Act-As-Customer`.
- `GET /me/companies` returns `[{id, name}]` for the login's companies (the JWT has ids and
  per-company permissions, not names).

## Customer-editable resources

- `PATCH /documents/{id}` renames `filename` only (sanitised `a-z 0-9 . _ -`, same as upload). The UUID
  `storage_path` is untouched. Scoped to the JWT customer; a real miss is 404 so ids can't be
  enumerated. **Gotcha:** PDO runs without `MYSQL_ATTR_FOUND_ROWS`, so `rowCount()` reports *changed*
  rows; `0` triggers an ownership SELECT before 404ing, so renaming to the current name isn't a 404.
- `PATCH /messages/{id}` edits the body. Customers edit their own `author_type='customer'` messages;
  admins any. Sets `edited_at = NOW()`. Body 1–10 000 chars.

## Documents

Stored on disk under `$DOCUMENT_ROOT_DIR/{customer_id}/`, **outside the webroot** (the installer creates
`~/customer-files/`). The DB row holds `storage_path` relative to that root.

## Open issues

- #7 Stripe Customer Portal integration (deferred).
