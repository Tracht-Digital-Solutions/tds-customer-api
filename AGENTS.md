# Agent notes — tds-customer-api

PHP 8.3 + Slim 4 + PDO + Phinx + Stripe + JWKS verification. Owns
the `tds_customer` MariaDB database and customer document storage
on the production host's filesystem under `\$DOCUMENT_ROOT_DIR/{customer_id}/`.

## Behind the gateway

The public surface `api.tracht-digital.de/customer/*` is fronted by
`tds-api-gateway`, a Slim reverse proxy that strips the `/customer` prefix and
forwards to this service (so `…/customer/admin/projects` → this app's
`/admin/projects`). The path contract is unchanged — routes here still mount
at root. The build model is dev/release (see README): a push to `main` auto-assembles the **`dev`** bundle (developer artifact, not deployed); the manual **Release** workflow (`release.yml`) assembles the **`release`** bundle, pings the deploy webhook, and fires a `repository_dispatch(api-pushed)` to the gateway (needs `GATEWAY_DISPATCH_TOKEN`) so it reassembles its `dev` bundle.

## Mental model

- `JwksAuthMiddleware` fetches/caches the JWKS from `tds-auth-api`
  and verifies every Bearer JWT before actions run. Decoded claims
  attached as `request.getAttribute('claims')`. `BaseAction::customerId()`
  is the recommended accessor. The middleware depends on a tiny
  `Service\TokenVerifier` interface (one method, `verify`) so it
  can be unit-tested without spinning a JWKS server;
  `JwksClient implements TokenVerifier`, wired by the DI container.
- `Stripe\WebhookAction` is the **one** route NOT behind that
  middleware — Stripe authenticates via header signature, verified
  inside the action using `Webhook::constructEvent()`.
- Documents are stored on disk, NOT in the DB. The DB row holds
  the `storage_path` relative to `\$DOCUMENT_ROOT_DIR`.
- All actions extend `BaseAction` for the json + customerId helpers.

## Schema (8 migrations)

- `customer(id, email UNIQUE, name, created_at, updated_at)`
- `project(id, customer_id FK, title, status, start/target dates, description)`
- `milestone(id, project_id FK, title, status, due/completed dates, sort_order)`
- `invoice(id, customer_id FK, project_id FK?, amount_cents, currency, status, stripe_*, paid_at)`
- `document(id, customer_id FK, project_id FK?, filename, storage_path, mime_type, size_bytes, uploaded_at)`
- `message(id, customer_id FK, project_id FK?, author_type, body, created_at, read_at, edited_at)`
- `audit_log(id, actor_type, actor_id, action, method, path, target_type, target_id, status, ip, created_at)`
- `time_entry(id, project_id FK, milestone_id FK?, started_at, ended_at?, duration_minutes?, description, source, created_at, updated_at)`

Foreign keys cascade-delete from customer; project FK on invoice/
document/message uses `ON DELETE SET NULL` so deleting a project
doesn't lose the financial/document/comm history. `time_entry` is
the exception — it cascades from project (entries lose meaning
without their project) and only the milestone link is nullable.

## Time tracking

The `time_entry` table backs the admin time tracker (`/admin/time-
entries/*`) and the read-only customer breakdown (`/projects/{id}/
time-entries`). Invariant: at most one row at a time has `ended_at
IS NULL` (= the running timer). Enforced in the app via
`TimeEntryRepository::runningEntry()` rather than a DB constraint
— a partial unique index would need MySQL 8 + a generated column,
and the single-admin scenario doesn't justify it.

`source` enum is `manual | timer` — set automatically depending on
which entry point opened the row. Duration is always recomputed
server-side from `started_at` → `ended_at` so client clock skew
can't poison the data.

## Open issues

- #7  Stripe Customer Portal integration (deferred)

## Admin endpoints

All admin endpoints are gated by a **per-admin JWT** —
`JwksAuthMiddleware(requireAdmin: true)` requires an `admin=true` claim
(verified via JWKS). The shared `ADMIN_TOKEN` no longer gates them; it
survives only as the `SERVICE_TOKEN` fallback for the one server-to-server
call below.

- `POST /admin/customers` — creates a company. `{name, email, createLogin?}`.
  With `createLogin` true/omitted it also provisions an owner login: wraps the
  customer-row insert in a transaction and calls tds-auth-api
  `POST /admin/customer-credentials` (Bearer `SERVICE_TOKEN`) to create the
  app_user; rolls back the row if that fails. With `createLogin: false` it
  creates the company only — extra accounts are added via tds-auth-api
  `POST /admin/users` (several accounts per company).
- `GET /admin/customers` — company list for the admin user-management UI
  (group accounts by company / company picker).
- `GET /admin/projects` — flat project list with customer + milestones
  baked in, for the admin time-tracking picker.

## Portal permissions

Each customer-portal route is additionally gated by `RequirePermissionMiddleware`
checking the permission its account must hold — `projects:read`,
`invoices:read`/`invoices:pay`, `documents:read`/`documents:write`/
`documents:sign`, `messages:read`/`messages:write` (mirrors tds-shared's
`PORTAL_PERMISSIONS`). The permission comes from the JWT `permissions` claim;
admins bypass. Missing permission → 403. Permission changes take effect on the
user's next login (auth-api revokes their sessions on change).
- `/admin/time-entries/*` — CRUD plus `/timer`, `/timer/start`,
  `/timer/stop`. `TimeEntryRepository` centralises the running-timer
  lookup so the three timer actions agree on a single contract.

## Admin view — the `X-Act-As-Customer` header

`BaseAction::customerId()` resolves the *effective* customer a request is scoped
to, so an admin can inspect any customer's portal (the frontend's Admin-Ansicht):

- **Non-admin** → the JWT's own `customer_id` (unchanged; can't be overridden).
- **Admin** → the `X-Act-As-Customer: <id>` request header when present, else the
  admin's own linked `customer_id` if the token carries one, else **400** ("No
  customer selected") — the portal never issues scoped calls in that state, so
  this is just the guard behind it.

The header is honoured **only** for an `admin=true` token (`JwksAuthMiddleware`
verified it), so a non-admin echoing it changes nothing — no privilege
escalation. `CorsMiddleware` allowlists `X-Act-As-Customer` so the browser
preflight lets `app.` → `api.` send it cross-origin. Every portal action extends
`BaseAction`, so this is centralised — individual actions need no change.

## Customer-editable resources

Two endpoints let a customer modify their own data in place:

- `PATCH /documents/{id}` — renames `filename` only. The underlying
  `storage_path` is keyed by UUID and never shown, so we leave it
  alone. Same filename sanitisation as `UploadAction` (a-z 0-9 . _ -).
  WHERE clause scopes to the JWT-authed customer; non-matching
  rowCount returns 404 so document IDs can't be enumerated.
- `PATCH /messages/{id}` — edits body. Customer can edit own
  `author_type='customer'` messages; admin can edit any. Sets
  `edited_at = NOW()` so the frontend can render a "(bearbeitet)"
  indicator. Same body length validation as create (1–10 000 chars).

## Tests

PHPUnit 10. `composer test` runs the suite.

- **Pure unit**: `DocumentSigner` (HMAC round-trip, tamper +
  cross-customer + wrong-secret rejection, expiry), `BaseAction`
  (claim extraction LogicException paths + admin `X-Act-As-Customer`
  scoping: header wins, own-customer fallback, 400 when neither, and a
  non-admin's header is ignored), `AdminAuthMiddleware`,
  `JwksAuthMiddleware` (with `tests/Support/FakeTokenVerifier`).
- **Integration** against real MariaDB: `TimeEntryRepository`
  (timer + manual flows, ownership checks),
  `AuditLogMiddleware` (actor/target/IP recording, graceful
  failure when audit_log is unavailable),
  `Action\\Project\\ListAction` (cross-tenant isolation guard).
  Set `TDS_TEST_DB_DSN` (+ `_USER` / `_PASS`) to run; otherwise
  they skip. Tests drop + recreate the tables they touch on every
  run, so no `composer migrate` against the test DB.

See INSTALL.md §6 for the throwaway-Docker test DB recipe.

## Don't

- Don't put document paths under the webroot. `\$DOCUMENT_ROOT_DIR`
  must be outside `~/sites/`. The bootstrap installer creates
  `~/customer-files/` for this purpose.
- Don't run Stripe API calls outside the dedicated WebhookAction
  + PayAction. Keep the surface small.
- Don't let actions read `_GET[customer_id]` — always pull
  customer_id from the JWT via `BaseAction::customerId()`. Trusting
  query params here would cross trust boundaries.
- Don't write `$_ENV[$key] ?? getenv($key) ?: $default` in env
  helpers. PHP binds `??` tighter than `?:`, so this parses as
  `($_ENV[$key] ?? getenv($key)) ?: $default` and silently
  clobbers any legitimately falsy value (`"0"`, `""`) with the
  default. Use explicit `?? false` checks instead. Bit all four
  API repos at once via copy-paste — see #13 (this repo) /
  auth #11 / contact #7 / content #13.
- Don't add a `self::env('FOO')` (no default → required) without
  also adding `FOO=` to `.env.example`. We caught
  `DOCUMENT_SIGN_SECRET` and `ADMIN_TOKEN` drifting out of sync
  with the code in #14 — anyone copying the example to `.env`
  would have a non-booting app.
- Don't widen `Access-Control-Allow-Methods` in `CorsMiddleware`
  beyond the methods actually routed here, but don't forget to
  *narrow* it either: when a new method joins the router (e.g.
  PATCH/DELETE inside the JWT group), add it to the header in
  the same commit. #13 caught PATCH + DELETE missing for half
  the customer surface.
