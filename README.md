# tds-customer-api

Customer portal data API — projects, invoices, documents, messages.
**PHP 8.3 + Slim 4 + PDO + Phinx + Stripe** with **JWKS auth**
against `tds-auth-api`. Deploys to **netcup Webhosting 8000** at
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
| `GET` | `/healthz` | Liveness probe — DB/Stripe/blob storage state |
| `GET` | `/projects` | List customer's projects |
| `GET` | `/projects/{id}` | Project detail with milestones |
| `GET` | `/invoices` | List invoices |
| `POST` | `/invoices/{id}/pay` | Create Stripe Checkout session |
| `POST` | `/stripe/webhook` | Stripe → mark invoice paid (signature auth) |
| `GET` | `/documents?projectId=` | List documents |
| `POST` | `/documents` | Multipart upload (25 MB cap, mime allowlist) |
| `GET` | `/documents/{id}/download` | Stream file (JWT auth) |
| `POST` | `/documents/{id}/sign` | Issue a signed URL (default TTL 5 min, max 1 h) |
| `GET` | `/documents/sign?d=&c=&exp=&sig=` | Stream via signed URL — no JWT required |
| `GET` | `/messages?projectId=` | Message thread |
| `POST` | `/messages` | Send message (author derived from JWT) |

---

## Local dev

```bash
composer install
cp .env.example .env       # fill DB + Stripe + AUTH_API_URL +
                           # DOCUMENT_ROOT_DIR + DOCUMENT_SIGN_SECRET
composer migrate
composer start             # http://localhost:8004
```

Use Docker MariaDB:

```bash
docker run --rm -d --name tds-customer-maria \
  -e MARIADB_ROOT_PASSWORD=dev -e MARIADB_DATABASE=tds_customer_local \
  -p 3306:3306 mariadb:11
```

---

## Manual deploy

The repo ships an automated `.github/workflows/deploy.yml`. To
deploy by hand:

```bash
# 1. Install no-dev deps locally
composer install --no-dev --optimize-autoloader

# 2. SFTP the project (excluding .env, var/, vendor cache) to netcup
#    at ~/sites/api.tracht-digital.de/customer/releases/<TIMESTAMP>/

# 3. Run migrations + activate the release
#    https://api.tracht-digital.de/install.php?action=install-php
#        &target=customer
#        &release=<TIMESTAMP>
#        &migrate=1
#        &token=<INSTALL_TOKEN>
```

The shared `~/sites/api.tracht-digital.de/customer/shared/.env` on
netcup carries the secrets and is symlinked into each release.

---

## Configuration

| Env var | Purpose |
|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | MariaDB |
| `AUTH_API_URL` | JWKS endpoint base (e.g. `https://api.tracht-digital.de/auth`) |
| `JWKS_CACHE_TTL` | Default 600 s |
| `STRIPE_SECRET_KEY` | Stripe Checkout / portal calls |
| `STRIPE_WEBHOOK_SECRET` | Verifies `/stripe/webhook` signatures |
| `DOCUMENT_ROOT_DIR` | Outside webroot, mode 700 (`~/customer-files`) |
| `DOCUMENT_SIGN_SECRET` | HMAC secret for `/documents/{id}/sign` — **rotate to invalidate every outstanding signed URL** |
| `CORS_ALLOWED_ORIGINS` | Comma-separated frontend origins |
| `APP_ENV` | `production` strips stack traces |

GitHub Actions deploy workflow also needs:
- `secrets.NETCUP_FTP_HOST` / `NETCUP_FTP_USER` / `NETCUP_FTP_PASSWORD`
- `secrets.INSTALL_TOKEN`
- `vars.INSTALLER_URL`

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
| `#6` Customer onboarding | Pending — `customer_credential` lives in `tds-auth-api`. Needs a cross-API design (call into auth-api after inserting the `customer` row, or move the credential table here). |
| `#7` Stripe Customer Portal | Deferred per the issue — only file the work if the portal config justifies it before launch. |

---

## License

UNLICENSED — internal Tracht Digital Solutions project.
