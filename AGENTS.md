# AGENTS.md — tds-customer-api

The **legacy customer service**: PHP 8.3, Slim 4, PDO, Phinx, Stripe, JWKS verification. It owns
the `tds_customer` database and customer document storage under `$DOCUMENT_ROOT_DIR`, and is
served behind `tds-gateway-api` at `api.tracht-digital.de/customer/*`.

**Status: still live, do not retire yet, no new features.** Every feature has been ported to
frontend extensions (support tickets, time tracking, Lexware, customers, billing, projects,
documents, messages). Retirement waits on the go-live and on consolidating the two `customer`
tables; the admin user management still falls back to `/customer/admin/customers`. See
`../MIGRATION-STATUS.md`.

## Commands

```bash
composer install
composer start          # php -S localhost:8004 with public/router.php
composer test           # phpunit; DB tests need TDS_TEST_DB_DSN (+ _USER / _PASS)
composer migrate        # phinx, local env
```

## Hard rules

- `customer_id` always comes from the JWT via `BaseAction::customerId()`, never from the request.
- Every new migration class is prefixed with the service name; primary-key columns carry `'null' => false`.
- Env helpers use explicit `?? false` checks, never `$_ENV[$k] ?? getenv($k) ?: $default`.
- `CorsMiddleware` is added **after** `addRoutingMiddleware()`, and its allowed methods match the router.
- A required env var gets a line in `.env.example` in the same change.
- Document storage stays outside the webroot. Stripe calls stay in `PayAction` / `WebhookAction`.
- `NOW()` columns and UTC columns are never compared in one condition.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing auth, routes, permissions, admin endpoints or the active-company logic |
| [docs/agents/database.md](docs/agents/database.md) | Changing the schema, migrations, time handling or time tracking |
| [docs/agents/tickets.md](docs/agents/tickets.md) | Touching tickets, statuses, mail, IMAP or contact ingest |
| [docs/agents/settings.md](docs/agents/settings.md) | Touching `AppSettings` or `/admin/settings` |
| [docs/agents/pitfalls.md](docs/agents/pitfalls.md) | Any change; lists the traps that hit all APIs |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or running tests |

Setup and deployment: [INSTALL.md](INSTALL.md), [README.md](README.md). Workspace rules: `../CLAUDE.md`.
