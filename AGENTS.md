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

## Schema (13 migrations)

- `customer(id, email UNIQUE, name, created_at, updated_at)`
- `project(id, customer_id FK, title, status, start/target dates, description)`
- `milestone(id, project_id FK, title, status, due/completed dates, sort_order)`
- `invoice(id, customer_id FK, project_id FK?, amount_cents, currency, status, stripe_*, paid_at)`
- `document(id, customer_id FK, project_id FK?, filename, storage_path, mime_type, size_bytes, uploaded_at)`
- `message(id, customer_id FK, project_id FK?, author_type, body, created_at, read_at, edited_at)`
- `audit_log(id, actor_type, actor_id, action, method, path, target_type, target_id, status, ip, created_at)`
- `time_entry(id, project_id FK, milestone_id FK?, started_at, ended_at?, duration_minutes?, description, source, created_at, updated_at)`
- `ticket_status(id, name, color, sort_order, visible_to_customer, is_terminal, is_default, …)` — the admin-configurable status registry (seeded with 5 defaults)
- `ticket(id, customer_id FK, project_id FK?, status_id FK, subject, description, priority, type, assignee_user_id, created_by_type/_user_id, customer_action_required, customer_action_note, created_at, updated_at, closed_at)`
- `ticket_comment(id, ticket_id FK, author_type, author_user_id?, body, is_internal, created_at, edited_at)`
- `ticket_attachment(id, ticket_id FK, comment_id FK?, filename, storage_path, mime_type, size_bytes, uploaded_by_type, created_at)`
- `ticket_setting(setting_key PK, setting_value, updated_at)` — ticket-system settings (notification toggles). The string PK column is declared `null => false` explicitly: MySQL 8 rejects a nullable PRIMARY KEY (error 1171) where MariaDB silently coerces it — same gotcha handled in tds-auth-api's `session.jti`.

Foreign keys cascade-delete from customer; project FK on invoice/
document/message/ticket uses `ON DELETE SET NULL` so deleting a project
doesn't lose the financial/document/comm history. `time_entry` and the
ticket tables cascade from their parent. `ticket.status_id` is **RESTRICT**
(a status in use can't be deleted). `assignee_user_id` / `*_user_id` reference
tds-auth-api `app_user.id` and carry **no FK** (different service/DB).

## Tickets

The support ticket system (`/tickets` for customers, `/admin/tickets` for
admins). Customers open tickets, comment, and attach files; admins triage them —
assign to a **support agent** (an admin with `is_support_agent` in tds-auth-api;
the frontend fetches assignable agents from auth-api `/admin/users`), set
priority/type, move through statuses, add internal notes, and set a "customer
action required" prompt.

- **Statuses are runtime-configurable** (`ticket_status`), not a fixed ENUM.
  Each has a chip `color` (neutral|info|success|warning|danger), a
  `visible_to_customer` flag, an `is_terminal` flag (closing → stamps
  `closed_at`), and one `is_default` (new tickets start there). When a status is
  **not** visible to the customer, `TicketRepository::present(…, forCustomer:true)`
  swaps in a neutral "In Bearbeitung" fallback so internal stages never leak.
- **Internal notes** (`ticket_comment.is_internal`) are returned only to admin
  callers — customer read paths filter them out (`includeInternal: false`).
- **Read model** lives in `TicketRepository` (joins the status registry + applies
  per-audience visibility) rather than inline SQL, so every endpoint agrees.
  `TicketStatusRepository` owns the registry, `TicketSettings` the toggles.
- **Email notifications** (`TicketMailer`, Resend) are opt-in per event via the
  `ticket_setting` toggles AND no-op entirely when `RESEND_API_KEY` is unset —
  a failed send never breaks the ticket write. New ticket → admin inbox
  (`TICKET_ADMIN_EMAIL`); visible status change / public reply → customer.

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
`documents:sign`, `messages:read`/`messages:write`, `tickets:read`/`tickets:write`
(mirrors tds-shared's `PORTAL_PERMISSIONS`). The permission comes from the JWT `permissions` claim;
admins bypass. Missing permission → 403. Permission changes take effect on the
user's next login (auth-api revokes their sessions on change).
- `/admin/time-entries/*` — CRUD plus `/timer`, `/timer/start`,
  `/timer/stop`. `TimeEntryRepository` centralises the running-timer
  lookup so the three timer actions agree on a single contract.

## Admin view — the `X-Act-As-Customer` header

`BaseAction::customerId()` resolves the *effective* customer (the **active
company**) a request is scoped to. `Support\ActiveCompany` is the shared resolver
(used by both `BaseAction` and `RequirePermissionMiddleware`):

- **Non-admin (multi-company)** → the `X-Act-As-Customer: <id>` header when the
  login belongs to that company (from the JWT `companies` claim), else its
  primary/first company. So a multi-company user switches company via this
  header; a company they're **not** a member of is ignored (falls back to the
  primary — no escalation). **Permissions are per active company**:
  `RequirePermissionMiddleware` checks the permission set of the *active* company
  (`companies` claim), not a global list.
- **Admin** → the `X-Act-As-Customer: <id>` header for **any** customer when
  present, else the admin's own linked `customer_id`, else **400** ("No customer
  selected"). Admins bypass the permission check.

Back-compat: a token issued before multi-company (no `companies` claim) falls
back to the flat `customer_id` / `permissions` claims. `CorsMiddleware`
allowlists `X-Act-As-Customer`. `GET /me/companies` returns `[{id, name}]` for
the login's companies (auth-api's JWT has the ids + per-company perms but not the
names — those live here) so the portal's company switcher can label them.

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
  `Action\\Project\\ListAction` (cross-tenant isolation guard),
  `Action\\Ticket\\TicketActionsTest` (create/list, customer-visibility
  fallback, cross-tenant 404, reply clears the action flag, internal notes
  hidden from customers, terminal status closes, assign + filter, status
  delete-in-use 409).
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
  the customer surface; the ticket-settings `PUT` added `PUT` to
  the allowlist the same way.
