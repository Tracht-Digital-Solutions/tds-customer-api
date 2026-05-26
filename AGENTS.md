# Agent notes — tds-customer-api

PHP 8.3 + Slim 4 + PDO + Phinx + Stripe + JWKS verification. Owns
the `tds_customer` MariaDB database and customer document storage
on netcup's filesystem under `\$DOCUMENT_ROOT_DIR/{customer_id}/`.

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

All admin endpoints are gated by `AdminAuthMiddleware` (shared
`ADMIN_TOKEN`, Bearer). They run server-to-server from tds-admin
and don't go through the JWT path.

- `POST /admin/customers` — onboarding. Wraps the customer-row
  insert in a transaction and calls into tds-auth-api
  `POST /admin/customer-credentials` to store the argon2id-hashed
  temp password. If that downstream call fails, the customer row
  is rolled back so no account exists that can't log in.
- `GET /admin/projects` — flat project list with customer + milestones
  baked in, for the admin time-tracking picker.
- `/admin/time-entries/*` — CRUD plus `/timer`, `/timer/start`,
  `/timer/stop`. `TimeEntryRepository` centralises the running-timer
  lookup so the three timer actions agree on a single contract.

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
  (claim extraction LogicException paths), `AdminAuthMiddleware`,
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
