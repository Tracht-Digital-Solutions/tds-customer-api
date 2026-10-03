# Installation — tds-customer-api

> Part of the Tracht Digital Solutions multi-repo project.
> tds-customer-api is the **customer portal data API** — projects,
> invoices, documents, messages, time entries. Verifies JWTs against
> tds-auth-api's JWKS endpoint, integrates Stripe Checkout for invoice
> payment, and serves admin endpoints for the time tracker.
>
> **Bring this up after `tds-auth-api` is reachable** — every authenticated
> route fails if `AUTH_API_URL/.well-known/jwks.json` is unreachable.

## Prerequisites

| Tool | Version | Why |
|---|---|---|
| PHP | 8.3+ with `openssl`, `pdo_mysql`, `fileinfo`, `curl` | Runtime |
| Composer | 2.x | Dependency management |
| MariaDB | 11.x (or MySQL 8) | `tds_customer` database (8 tables) |
| Stripe account | — | Checkout sessions for invoice pay |
| Docker | optional | Local MariaDB |
| Production host | shared Apache/PHP | Deploy target |

## 1. Clone + install

```bash
git clone https://github.com/Tracht-Digital-Solutions/tds-customer-api.git
cd tds-customer-api
composer install
```

## 2. Local MariaDB (via Docker)

```bash
docker run --rm -d \
  --name tds-customer-maria \
  -e MARIADB_ROOT_PASSWORD=dev \
  -e MARIADB_DATABASE=tds_customer_local \
  -p 3310:3306 \
  mariadb:11
```

Port 3310 keeps the four local APIs from clashing (see
tds-auth-api's INSTALL.md for the port map).

## 3. Configure

```bash
cp .env.example .env
```

Fill in:

```ini
APP_ENV=local
DB_HOST=127.0.0.1
DB_PORT=3310
DB_NAME=tds_customer_local
DB_USER=root
DB_PASS=dev

# Must point at a running tds-auth-api for JWKS verification
AUTH_API_URL=http://localhost:8001
JWKS_CACHE_TTL=600

# Same value as tds-auth-api's + tds-content-api's ADMIN_TOKEN.
# Gates POST /admin/customers + the /admin/time-entries/* family +
# is what we send to tds-auth-api when onboarding credentials.
ADMIN_TOKEN=<your 32+ char hex string, same across the three APIs>

# Encrypts secret runtime settings (Stripe/SMTP/IMAP/Lexware secrets) at rest in
# the app_setting table with AES-256-GCM. Generate once per environment; the
# installer does this automatically. Empty = plaintext storage (dev only).
SETTINGS_ENCRYPTION_KEY=$(openssl rand -hex 32)

# Stripe — test keys for dev, live keys for prod. These (plus the SMTP/IMAP/
# Lexware settings) are now ALSO editable at runtime from the admin frontend
# (Einrichtungsassistent / Einstellungen); a non-empty DB value overrides the
# env var. Leave blank to configure entirely via the admin frontend.
STRIPE_SECRET_KEY=sk_test_xxxxxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxx

# Document storage. MUST be outside the webroot. PHP must have 700
# write perms here. The directory tree is {customer_id}/{uuid}-{name}.
DOCUMENT_ROOT_DIR=./var/customer-files

# HMAC secret for signed download URLs. Rotate to invalidate every
# outstanding signed URL.
DOCUMENT_SIGN_SECRET=$(openssl rand -hex 32)

# CORS — every customer + admin frontend origin
CORS_ALLOWED_ORIGINS=http://localhost:4321,https://app.tracht-digital.de,https://management.tracht-digital.de
```

```bash
mkdir -p var/customer-files
chmod 700 var/customer-files
```

## 4. Migrate + run

The repo ships **8 migrations**, including the most recent two that
back the time-tracking + message-edit features:

- `20260507000001` – customer table
- `20260507000002` – project + milestone
- `20260507000003` – invoice (Stripe-linked)
- `20260507000004` – document
- `20260507000005` – message
- `20260511000001` – audit_log
- `20260519000001` – **time_entry** (time tracking)
- `20260519000002` – **message.edited_at** column (inline message edit)

Run them all:

```bash
composer migrate
composer start         # http://localhost:8004
```

## 5. Verify

You need a valid customer JWT from `tds-auth-api` for most calls —
spin up tds-auth-api first, log in via its `POST /customer/login`,
then use the issued JWT as a Bearer here.

```bash
# Liveness (public)
curl -i http://localhost:8004/healthz

# Admin onboarding (Bearer ADMIN_TOKEN — server-to-server call)
curl -sX POST http://localhost:8004/admin/customers \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Test Customer","email":"test@example.com"}' | jq
# 201 with {customer, tempPassword} — note the tempPassword, it's
# returned once and never again

# Customer list (Bearer customer JWT)
curl -s http://localhost:8004/projects \
  -H "Authorization: Bearer $CUSTOMER_JWT" | jq

# Admin time entries (Bearer ADMIN_TOKEN)
curl -s http://localhost:8004/admin/time-entries \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq

# Stripe webhook (signed by Stripe)
# Use the Stripe CLI for local testing:
stripe listen --forward-to localhost:8004/stripe/webhook
```

## 6. Tests

Pure unit tests cover `DocumentSigner` (HMAC round-trip + tamper +
expiry), `BaseAction` (`customerId` claim extraction), both auth
middlewares (`AdminAuthMiddleware`, `JwksAuthMiddleware` — via a
`TokenVerifier` stub so we don't spin a JWKS server). They run
without any external dependencies:

```bash
composer test
```

The following are **integration tests** that exercise real MariaDB —
`TimeEntryRepositoryTest`, `AuditLogMiddlewareTest`,
`Action\Project\ListActionTest` (cross-tenant isolation guard).
Without `TDS_TEST_DB_DSN` set they skip cleanly.

Spin up a throwaway test DB (port `3399` so it doesn't clash with the
four per-repo dev DBs):

```bash
docker run --rm -d \
  --name tds-test-maria \
  -e MARIADB_ROOT_PASSWORD=test \
  -e MARIADB_DATABASE=tds_test \
  -p 3399:3306 \
  mariadb:11
```

Export the connection and re-run the suite:

```bash
export TDS_TEST_DB_DSN="mysql:host=127.0.0.1;port=3399;dbname=tds_test;charset=utf8mb4"
export TDS_TEST_DB_USER=root
export TDS_TEST_DB_PASS=test
composer test
```

The integration tests drop + recreate the tables they touch on every
run (incl. setting up `customer`, `project`, `milestone` for the
foreign-key parents on `time_entry`), so no `composer migrate` against
the test DB is needed. The same container can be reused by every TDS
API's test suite — just don't run two of them in parallel against it
(the schemas overlap).

## 7. Production deployment

In production this API ships inside the **`tds-gateway-api` bundle** as
`services/customer/` and is served by the gateway in its default **in-process**
mode (`GATEWAY_MODE=inprocess`): one PHP-FPM app for the whole API surface, **no
per-service `php -S` process to start**. The full release recipe (Plesk Git
checkout of the gateway's `release` branch, docroot on `gateway/public`, DBs,
migrations, `.env`s — most of it via the `/install.php` wizard) lives in the
gateway repo's **`DEPLOY-PLESK.md`**.

This service's config lives at `services/customer/.env` on the host (untracked,
survives deploys). **`DOCUMENT_ROOT_DIR` on production points at
`~/customer-files/` outside the release tree** so documents survive across
releases. A manual *Actions → Release* on this repo dispatches a gateway
re-assemble + deploy; pushes to `main` only build the (undeployed) `dev` bundle.

> **Migration heads-up**: if you're deploying for the first time after
> 2026-05, the two new time-tracking migrations must run. The deploy
> hook runs migrations on activation; verify both `time_entry` and the
> updated `message` table are present afterwards.

## 8. Wire Stripe Webhook

In production:
- Stripe Dashboard → Developers → Webhooks
- Endpoint: `https://api.tracht-digital.de/customer/stripe/webhook`
- Events: `checkout.session.completed`, `payment_intent.payment_failed`
- Reveal the signing secret, paste into the production host `.env` as
  `STRIPE_WEBHOOK_SECRET`

## Related repos

- [tds-shared-pkg](https://github.com/Tracht-Digital-Solutions/tds-shared-pkg) — types for `Project`, `Invoice`, `Document`, `Message`, etc.
- [tds-auth-api](https://github.com/Tracht-Digital-Solutions/tds-auth-api) — JWKS source; `POST /admin/customer-credentials` is called from here during onboarding
- [tds-customer-legacy-frontend](https://github.com/Tracht-Digital-Solutions/tds-customer-legacy-frontend) — customer portal frontend; consumes every customer-scoped endpoint
- [tds-admin](https://github.com/Tracht-Digital-Solutions/tds-admin) — `/time` page consumes `/admin/time-entries/*` + `/admin/projects`

## Troubleshooting

**Every authed request returns `401 No token presented`.**
JWT not attached. Customer frontend uses cookie auth
(`credentials: 'include'`); admin uses Bearer.

**`401 Invalid token: Key set not found`.**
JWKS endpoint of tds-auth-api unreachable. Check `AUTH_API_URL` and
that the auth-api is running.

**`PATCH /messages/{id}` returns 500 with column not found.**
The `edited_at` migration didn't run. Run `composer migrate` (local);
in production the deploy hook runs migrations on activation.

**`POST /documents` returns 503 "storage unavailable".**
`DOCUMENT_ROOT_DIR` doesn't exist or isn't writable. Recreate +
chmod 700.

**Stripe webhook returns 400 `Webhook signature verification failed`.**
`STRIPE_WEBHOOK_SECRET` mismatch. Get the secret from the Stripe
Dashboard endpoint config, not from a test event.

**`composer test` fails with `Cannot find PHPUnit`.**
You installed with `--no-dev`. Re-run plain `composer install` to
pull `phpunit/phpunit` from `require-dev`.
