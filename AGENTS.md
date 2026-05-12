# Agent notes — tds-customer-api

PHP 8.3 + Slim 4 + PDO + Phinx + Stripe + JWKS verification. Owns
the `tds_customer` MariaDB database and customer document storage
on netcup's filesystem under `\$DOCUMENT_ROOT_DIR/{customer_id}/`.

## Mental model

- `JwksAuthMiddleware` fetches/caches the JWKS from `tds-auth-api`
  and verifies every Bearer JWT before actions run. Decoded claims
  attached as `request.getAttribute('claims')`. `BaseAction::customerId()`
  is the recommended accessor.
- `Stripe\WebhookAction` is the **one** route NOT behind that
  middleware — Stripe authenticates via header signature, verified
  inside the action using `Webhook::constructEvent()`.
- Documents are stored on disk, NOT in the DB. The DB row holds
  the `storage_path` relative to `\$DOCUMENT_ROOT_DIR`.
- All actions extend `BaseAction` for the json + customerId helpers.

## Schema (5 migrations)

- `customer(id, email UNIQUE, name, created_at, updated_at)`
- `project(id, customer_id FK, title, status, start/target dates, description)`
- `milestone(id, project_id FK, title, status, due/completed dates, sort_order)`
- `invoice(id, customer_id FK, project_id FK?, amount_cents, currency, status, stripe_*, paid_at)`
- `document(id, customer_id FK, project_id FK?, filename, storage_path, mime_type, size_bytes, uploaded_at)`
- `message(id, customer_id FK, project_id FK?, author_type, body, created_at, read_at)`

Foreign keys cascade-delete from customer; project FK on invoice/
document/message uses `ON DELETE SET NULL` so deleting a project
doesn't lose the financial/document/comm history.

## Open issues

- #7  Stripe Customer Portal integration (deferred)

`#6` (customer onboarding) is closed on the tracker but the admin
endpoints aren't in `src/Action/` — reopen or implement before
production cutover.

## Don't

- Don't put document paths under the webroot. `\$DOCUMENT_ROOT_DIR`
  must be outside `~/sites/`. The bootstrap installer creates
  `~/customer-files/` for this purpose.
- Don't run Stripe API calls outside the dedicated WebhookAction
  + PayAction. Keep the surface small.
- Don't let actions read `_GET[customer_id]` — always pull
  customer_id from the JWT via `BaseAction::customerId()`. Trusting
  query params here would cross trust boundaries.
