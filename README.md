# tds-customer-api

> **Setting this up from scratch?** See [`INSTALL.md`](INSTALL.md) for
> the step-by-step bring-up (composer → MariaDB → Stripe → env →
> 8 migrations → smoke test). This README documents endpoints,
> configuration and operational notes.

---


Customer portal data API — projects, invoices, documents, messages.
**PHP 8.3 + Slim 4 + PDO + Phinx + Stripe** with **JWKS auth**
against `tds-auth-api`. Deploys to **the production host** at
`https://api.tracht-digital.de/customer/`.

---

## Endpoints

All require a customer JWT (`admin=false, customer_id=N`) issued by
`tds-auth-api`, except:
- **`/stripe/webhook`** — Stripe authenticates via `Stripe-Signature` header.
- **`/documents/sign`** — the URL's HMAC is the auth.
- **`/healthz`** — public liveness probe.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/healthz` | Liveness probe — `db` (`ok`/`no-schema`/`down`) + Stripe/blob storage state. Always HTTP 200; the gateway aggregate reads `db`. |
| `POST` | `/admin/customers` | **Admin onboarding** (Bearer `ADMIN_TOKEN`): insert customer + ask tds-auth-api to store credential; returns `{customer, tempPassword}` once |
| `GET` | `/admin/projects` | **Admin**: flat list of all projects with customer + milestones (used by the admin time-tracking picker) |
| `GET` | `/admin/time-entries` | **Admin**: list time entries with filters (`projectId`, `customerId`, `from`, `to`, `includeRunning`) |
| `POST` | `/admin/time-entries` | **Admin**: manual entry — accepts either `ended_at` or `duration_minutes`, fills the other |
| `PATCH` | `/admin/time-entries/{id}` | **Admin**: update an entry (refuses to edit a still-running timer row) |
| `DELETE` | `/admin/time-entries/{id}` | **Admin**: delete an entry |
| `GET` | `/admin/time-entries/timer` | **Admin**: current running timer row joined with project + milestone titles, or `null` |
| `POST` | `/admin/time-entries/timer/start` | **Admin**: open a row with `ended_at IS NULL`; 409 if one is already running |
| `POST` | `/admin/time-entries/timer/stop` | **Admin**: finalise the running entry (server computes duration from `started_at` → `NOW()`) |
| `GET` | `/projects` | List customer's projects |
| `GET` | `/projects/{id}` | Project detail with milestones |
| `GET` | `/projects/{id}/time-entries` | Read-only finished time entries for the customer's own project, with total + per-milestone breakdown |
| `GET` | `/invoices` | List invoices |
| `POST` | `/invoices/{id}/pay` | Create Stripe Checkout session |
| `POST` | `/stripe/webhook` | Stripe → mark invoice paid (signature auth) |
| `GET` | `/documents?projectId=` | List documents |
| `POST` | `/documents` | Multipart upload (25 MB cap, mime allowlist) |
| `PATCH` | `/documents/{id}` | Rename the user-visible filename only (`storage_path` is left alone) |
| `GET` | `/documents/{id}/download` | Stream file (JWT auth) |
| `POST` | `/documents/{id}/sign` | Issue a signed URL (default TTL 5 min, max 1 h) |
| `GET` | `/documents/sign?d=&c=&exp=&sig=` | Stream via signed URL — no JWT required |
| `GET` | `/messages?projectId=` | Message thread (response includes `edited_at` per row) |
| `POST` | `/messages` | Send message (author derived from JWT) |
| `PATCH` | `/messages/{id}` | Edit body. Customer can edit own `author_type='customer'` messages; admin can edit any. Sets `edited_at = NOW()`. |

---

## Local dev

```bash
composer install
cp .env.example .env       # fill DB + Stripe + AUTH_API_URL +
                           # DOCUMENT_ROOT_DIR + DOCUMENT_SIGN_SECRET
composer migrate           # local dev only — prod is auto-migrated (see below)
composer start             # http://localhost:8004
composer test              # run the PHPUnit suite (see INSTALL.md §6)
```

Use Docker MariaDB:

```bash
docker run --rm -d --name tds-customer-maria \
  -e MARIADB_ROOT_PASSWORD=dev -e MARIADB_DATABASE=tds_customer_local \
  -p 3306:3306 mariadb:11
```

---

## Deploy

Deployment is automatic. On a push to `main`, once CI passes, the
`deploy` job in [`.github/workflows/ci.yml`](.github/workflows/ci.yml)
POST-pings the deploy webhook and the production host pulls the new
release and activates it.

**Migrations on production apply automatically.** This service is served
in-process by the `tds-gateway-api` bundle, which runs each service's pending
Phinx migrations on the first request after a deploy — in-process via Phinx's
`Manager` API, no `proc_open`, no CLI php (see the gateway's `AGENTS.md` →
*Auto-migration*). So new migrations (e.g. `create_time_entry`,
`add_message_edited_at`) apply themselves on the next deploy — no manual
`composer migrate:prod`. `/healthz` reports the schema state in its `db` field
(`ok` / `no-schema` / `down`); a reachable-but-un-migrated DB shows `no-schema`
and flips the gateway aggregate to `503`.

**Required secret:** set `DEPLOY_WEBHOOK_URL` (repository secret) to the
host's deploy-hook URL — the deploy token is carried inside the URL. If
it isn't set, the deploy ping is skipped (CI still runs).

The shared `~/sites/api.tracht-digital.de/customer/shared/.env` on the
production host carries the secrets and is symlinked into each release.

---

## Configuration

| Env var | Purpose |
|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | MariaDB |
| `AUTH_API_URL` | tds-auth-api base — used for both JWKS verification and the onboarding S2S call (e.g. `https://api.tracht-digital.de/auth`) |
| `ADMIN_TOKEN` | Legacy shared secret — no longer gates the admin endpoints (those use a per-admin JWT via JWKS). Survives only as the `SERVICE_TOKEN` fallback: the Bearer we send on the server-to-server `POST /admin/customer-credentials` call to tds-auth-api. Same value as tds-auth-api's `ADMIN_TOKEN`/`SERVICE_TOKEN`. |
| `JWKS_CACHE_TTL` | Default 600 s |
| `STRIPE_SECRET_KEY` | Stripe Checkout / portal calls |
| `STRIPE_WEBHOOK_SECRET` | Verifies `/stripe/webhook` signatures |
| `DOCUMENT_ROOT_DIR` | Outside webroot, mode 700 (`~/customer-files`) |
| `DOCUMENT_SIGN_SECRET` | HMAC secret for `/documents/{id}/sign` — **rotate to invalidate every outstanding signed URL** |
| `CORS_ALLOWED_ORIGINS` | Comma-separated frontend origins |
| `APP_ENV` | `production` strips stack traces |

The only deploy secret is `DEPLOY_WEBHOOK_URL` (the host's deploy-hook
URL). The old `FTP_*` / `INSTALL_TOKEN` Repository Secrets and the
`INSTALLER_URL` variable are unused and can be cleaned up at your leisure.

---

## Audit log

Every authenticated request is logged to the `audit_log` table by
`AuditLogMiddleware` — one row per request with actor (customer or
admin), method, path, target type/id, response status, and IP. Logging
failures are swallowed so a transient DB hiccup never 5xx's a
customer-facing request.

Retention is left to a daily cron pruning rows older than 90 days:

```sql
DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 90 DAY;
```

---

## Signed-URL downloads

`POST /documents/{id}/sign` → `{ url, expiresAt }`. Default TTL 5 min,
max 1 h via `{"ttl": 3600}` body.

```json
{
  "url": "https://api.tracht-digital.de/customer/documents/sign?d=42&c=7&exp=1714838400&sig=abc…",
  "expiresAt": "2026-05-11T19:00:00+00:00"
}
```

The URL works without `credentials: 'include'` — safe to drop into
an `<img src>` or share with a preview pane. Signature is
HMAC-SHA256 over `documentId.customerId.exp` using
`DOCUMENT_SIGN_SECRET`. Ownership is re-verified at download time
against the customer_id in the URL, so a pulled customer can't have
their cached signed URL serve files after the row goes away.

---

## Known gaps

| Issue | Status |
|---|---|
| `#7` Stripe Customer Portal | Deferred per the issue — only file the work if the portal config justifies it before launch. |

---

## License

UNLICENSED — internal Tracht Digital Solutions project.
