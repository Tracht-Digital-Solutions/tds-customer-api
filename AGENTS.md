# Agent notes — tds-customer-api

PHP 8.3 + Slim 4 + PDO + Phinx + Stripe + JWKS verification. Owns
the `tds_customer` MariaDB database and customer document storage
on the production host's filesystem under `\$DOCUMENT_ROOT_DIR/{customer_id}/`.

> Status: **PARTIALLY SUPERSEDED — still live, do NOT retire yet.** Ported to frontend
> extensions: support tickets → `tds-ext-support-tickets-pkg`, time tracking →
> `tds-ext-time-tracker-pkg`, Lexware → `tds-ext-lexware-pkg`, the customer directory →
> `tds-ext-customers-pkg`, Stripe invoices → `tds-ext-billing-pkg`. **Not yet ported:**
> projects/milestones, documents, messages. The frontend's user-management still queries
> this service's `/customer/admin/customers` live until `tds-ext-customers-pkg` is published.
> This service remains the live backend until backend cutover. See the root
> `MIGRATION-STATUS.md`.

## Behind the gateway

The public surface `api.tracht-digital.de/customer/*` is fronted by
`tds-gateway-api`, a Slim reverse proxy that strips the `/customer` prefix and
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

## Schema (14 migrations)

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
- `app_setting(setting_key PK, setting_value TEXT, updated_at)` — runtime store for the non-installation-relevant third-party config the admin edits in tds-admin (Stripe, ticket mailer, Lexware). Same generic shape as `ticket_setting` but `setting_value` is `TEXT` to hold base64 AES-256-GCM ciphertext. **No seed rows** — an absent key means "fall back to `.env`". See `AppSettings` below. Its migration is `20260705000001_create_customer_app_setting.php` / `CreateCustomerAppSetting` — **service-prefixed on purpose**: the gateway's in-process auto-migrate loads every service's migration files into one PHP process, so migration class names must be unique across all four APIs (three services shipping an identical `CreateAppSetting` was an uncatchable fatal that took the whole API down). Prefix every new migration's class with the service name.

**That NOT NULL on a PK column is now enforced, not remembered (0.8.3).** The
same omission shipped twice in tds-auth-api and only surfaced when the gateway's
`/install.php` died mid-run on a fresh host — MariaDB, which dev/CI and every
DB-backed test here use, coerces the column silently. Two guards:
`tests/Support/MigrationDialectTest` scans `db/migrations` statically for a
`primary_key` column lacking `'null' => false` (no DB needed), and `_pipeline.yml`
runs a `mysql:8` service alongside MariaDB and applies the whole migration set to
an empty MySQL 8 database on every run. `phinx.php` falls back to `getenv()` so
that step works without a `.env` — PHP's `variables_order` (`GPCS`) leaves `$_ENV`
unpopulated from the real environment; a real `.env` still wins.

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
- **Email notifications** (`TicketMailer` → `SmtpMailer`, SMTP) are opt-in per
  event via the `ticket_setting` toggles AND no-op entirely when SMTP is
  unconfigured (`SMTP_HOST`/`SMTP_FROM` empty) — a failed send never breaks the
  ticket write. New ticket → admin inbox (`TICKET_ADMIN_EMAIL`); visible status
  change / public reply → customer. Customer-facing mails carry
  `Reply-To = TICKET_INBOX_ADDRESS` (the IMAP-monitored inbox) and keep the
  `#<id>` subject marker so a reply threads back via the ingester (below).
- **Inbound email → tickets** (`ImapTicketIngest`). One `poll()` pass connects to
  the configured IMAP mailbox, fetches UNSEEN mail and, per message: resolves the
  sender via `customer.email` (**unknown senders are skipped/logged**, never
  ticketed), dedupes on the `Message-ID`, threads onto an existing ticket when a
  `#<id>` subject marker or an `In-Reply-To`/`References` match belongs to that
  sender (else opens a `source='email'` ticket), stores allowed attachments
  (`AttachmentStorage::storeBytes`) and marks the message `\Seen`. Two new columns
  carry this: `ticket.source` + `ticket.email_message_id` and
  `ticket_comment.email_message_id` (migration `CustomerAddTicketEmailFields`).
  webklex/php-imap talks IMAP over stream sockets (no `ext-imap`, no `proc_open`),
  so it runs in-process. **No worker on prod:** `poll()` is driven by an external
  scheduler hitting the secret-gated `POST /tickets/ingest` (INGEST_TOKEN) — see
  `.github/workflows/imap-poll.yml` + the Plesk-scheduled-task alternative — and by
  the manual `POST /admin/tickets/ingest` ("Jetzt abrufen") button;
  `GET /admin/tickets/imap-test` backs "Verbindung testen". The message-parsing
  helpers on `ImapTicketIngest` are pure/static and unit-tested
  (`ImapTicketIngestParseTest`); `handle()` DB behaviour is in
  `ImapTicketIngestTest`; a live fetch is a manual check.
- **Contact-form → tickets** (`POST /tickets/contact`, `ContactIngestAction`).
  tds-contact-api forwards each contact-form submission here (server-to-server,
  same `INGEST_TOKEN` secret auth as `/tickets/ingest`, no JWT). It opens a ticket
  categorised `type='contact'`, `source='contact'`, carrying the submitter's
  contact details **structurally** in `from_name`/`from_email`/`from_company`
  (migration `CustomerAddTicketContactFields`). The submitter is usually not a
  customer, so **`customer_id` is nullable** — a contact ticket with no customer
  belongs to nobody and is therefore admin-only (the customer portal lists strictly
  by `customer_id`). When the submitter's email matches a `customer.email`, the
  ticket is bound to that customer instead (mirroring the IMAP path). The admin
  list `LEFT JOIN`s customer and falls back to `from_*` for the display name; a
  `type` filter separates contact tickets from support tickets. `notifyEmail()`
  (on `TicketRepository`) resolves the reply/status recipient as the customer email
  **or** the submitter's `from_email`, so admin replies reach contact submitters.
  A contact submitter's own email reply threads back onto their ticket via
  `findContactTicketForReply()` (matched on `from_email` + the `#<id>` marker) even
  though they have no customer account — brand-new mail from an unknown sender is
  still dropped (anti-spam). Coverage: `ContactIngestActionTest` +
  `ImapTicketIngestTest::test_contact_sender_reply_threads_onto_contact_ticket`.

## Runtime service config (`AppSettings` + `/admin/settings`)

The non-installation-relevant third-party config — Stripe (`STRIPE_SECRET_KEY`,
`STRIPE_WEBHOOK_SECRET`, `STRIPE_PUBLIC_KEY`, `STRIPE_RETURN_URL`), the SMTP ticket
mailer (`SMTP_HOST`/`SMTP_PORT`/`SMTP_USER`/`SMTP_PASSWORD`/`SMTP_SECURITY`/
`SMTP_FROM`, `TICKET_ADMIN_EMAIL`, `TICKET_INBOX_ADDRESS`), the IMAP inbox
(`IMAP_HOST`/`IMAP_PORT`/`IMAP_USER`/`IMAP_PASSWORD`/`IMAP_SECURITY`/`IMAP_FOLDER`,
`INGEST_TOKEN`) and Lexware (`LEXWARE_API_KEY`, `LEXWARE_API_URL`,
`LEXWARE_DEFAULT_HOURLY_RATE`, `LEXWARE_TAX_RATE_PERCENT`) — is edited **at
runtime** from tds-admin (Einrichtungsassistent / Einstellungen), not baked into
`.env` by the installer.

- **`AppSettings` service** (`src/Service/AppSettings.php`) reads/writes the
  `app_setting` table. `setting_key` == the env var name (1:1). **Precedence:** a
  non-empty DB value wins, else the env var (safe `?? false` precedence), else the
  coded default. So existing `.env` deployments keep working and a blank DB row
  never shadows a configured env var.
- **Secrets encrypted at rest.** Keys flagged `secret` in the registry
  (`STRIPE_SECRET_KEY`/`STRIPE_WEBHOOK_SECRET`/`SMTP_PASSWORD`/`IMAP_PASSWORD`/
  `INGEST_TOKEN`/`LEXWARE_API_KEY`)
  are AES-256-GCM-encrypted under `SETTINGS_ENCRYPTION_KEY`, stored as
  `gcm:base64(iv|tag|ciphertext)`. Unset key ⇒ plaintext fallback +
  `encryptionAvailable=false` (dev only). `deriveKey()` sha256's the configured
  secret to 32 bytes.
- **`GET /admin/settings`** returns the **masked**, section-grouped state
  (`configured`/`last4`/`source` for secrets; full `value` for non-secrets) plus
  `encryptionAvailable` — never a raw secret. **`PUT /admin/settings`** takes a
  flat `{KEY: value}` map; a **blank secret means "keep existing"** (so the masked
  UI needn't round-trip the real secret), a blank non-secret clears the override.
  Both behind `$adminJwt`, next to the ticket-settings routes.
- **Consumers read DB-first, lazily.** `PayAction`/`WebhookAction` take an
  `AppSettings` constructor param; `TicketMailer` + the Lexware factories resolve
  it inside their **lazy** container factories; `HealthAction` gets a
  `\Closure(): AppSettings` (like its lazy PDO) and its `checkStripe()` reports
  configured when DB **or** env has the key, in try/catch. Boot stays DB-free —
  none of these resolve at `createApp()`, so `/healthz` survives a DB outage. The
  store's own `dbValues()` also swallows a query failure (un-migrated table mid-
  deploy) and falls back to env-only.

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
(mirrors tds-shared-pkg's `PORTAL_PERMISSIONS`). The permission comes from the JWT `permissions` claim;
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
  WHERE clause scopes to the JWT-authed customer; a real miss returns
  404 so document IDs can't be enumerated. **Gotcha:** this PDO runs
  without `MYSQL_ATTR_FOUND_ROWS`, so `rowCount()` on the UPDATE reports
  *changed* rows — 0 when the sanitised name already matches (no
  timestamp is bumped). A `rowCount()===0` therefore probes ownership
  with a follow-up SELECT before 404ing, so renaming to the current
  name (or one that sanitises to it) isn't a spurious 404.
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
- Don't add `CorsMiddleware` before `addRoutingMiddleware()`. Slim
  middleware is LIFO — the LAST added runs FIRST — so CORS must be added
  AFTER routing/error to be outermost. Added earlier, the routing
  middleware 405s every OPTIONS preflight (no OPTIONS routes exist) before
  CORS can short-circuit it, and browsers block every cross-origin
  JSON/Authorization/X-Act-As-Customer request from both frontends. Bit all
  four API repos at once via copy-paste; `tests/PreflightTest.php` (an
  OPTIONS request through the REAL `Bootstrap::createApp()` app) is the
  regression guard.
- Don't run `php -S` without `public/router.php` (`composer start` passes
  it). Without a router script the built-in server 404s any dotted path
  that has no file on disk — the JWKS fetch from tds-auth-api breaks and
  every endpoint 401s. Apache (.htaccess) and the gateway's in-process
  mode don't need it.

## Tests — AttachmentStorage

`tests/Service/AttachmentStorageTest.php` (22 tests) covers where ticket
attachment bytes land on disk. Two of the guards here are the only thing
between an untrusted upload and the filesystem:

- **the filename is sanitised** before it is used as a path segment. The bytes
  arrive from a customer upload or, worse, from an IMAP message — a name of
  `../../etc/passwd` must not escape the customer's own directory. Asserted
  both structurally (no separator survives) and by resolving the written file
  and checking it is still under the root.
- **the mime allowlist and the size cap** decide what is stored at all. The
  allowlist itself is asserted to carry no `text/html` or `image/svg+xml`,
  since a stored attachment served back as an active page is the interesting
  attack; the cap is pinned at its exact boundary, because an off-by-one there
  rejects a legitimate 25 MB attachment.

Layout invariants: files are per-customer (`{customer}/tickets/…`, which is
what the download route authorises against) and carry a uuid, so two uploads
of the same filename cannot overwrite each other.

`storeBytes()` is the IMAP-ingest path and returns **null rather than
throwing** — one bad MIME part must not fail a whole incoming email — so every
rejection is asserted to be a null, not an exception.

Verified by mutation: 17 deliberate breakages introduced, 17 caught.
