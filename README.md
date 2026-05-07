# tds-customer-api

Customer portal data API — projects, invoices, documents, messages.
PHP 8.3 + Slim 4 + PDO + Phinx + Stripe + JWKS auth.
Deploys to **netcup Webhosting 8000** at
`https://api.tracht-digital.de/customer/`.

## Endpoints

All require a customer JWT (`admin=false, customer_id=N`) issued by
`tds-auth-api`, except the Stripe webhook which uses signature auth.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/projects` | List customer's projects |
| `GET` | `/projects/{id}` | Project detail with milestones |
| `GET` | `/invoices` | List invoices |
| `POST` | `/invoices/{id}/pay` | Create Stripe Checkout session |
| `POST` | `/stripe/webhook` | Stripe → mark invoice paid (signature auth) |
| `GET` | `/documents?projectId=` | List documents |
| `POST` | `/documents` | Multipart upload (25 MB cap, mime allowlist) |
| `GET` | `/documents/{id}/download` | Stream file |
| `GET` | `/messages?projectId=` | Message thread |
| `POST` | `/messages` | Send message (author derived from JWT) |

## Local dev

```bash
composer install
cp .env.example .env       # fill DB + Stripe + AUTH_API_URL
composer migrate
composer start             # http://localhost:8004
```

Use Docker MariaDB:

```bash
docker run --rm -d --name tds-customer-maria \
  -e MARIADB_ROOT_PASSWORD=dev -e MARIADB_DATABASE=tds_customer_local \
  -p 3306:3306 mariadb:11
```

## Deploy

Push to `main`. GitHub Actions installs deps, SFTPs to
`~/sites/api.tracht-digital.de/customer/releases/<TS>/`, then triggers
`install.php?action=install-php&target=customer&migrate=1`.

## Required GitHub secrets / vars

- `secrets.NETCUP_FTP_HOST` / `NETCUP_FTP_USER` / `NETCUP_FTP_PASSWORD`
- `secrets.INSTALL_TOKEN`
- `vars.INSTALLER_URL`

Plus on netcup (`~/sites/api.tracht-digital.de/customer/shared/.env`):
- DB creds for `tds_customer`
- `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
- `AUTH_API_URL=https://api.tracht-digital.de/auth`
- `DOCUMENT_ROOT_DIR=/home/<netcup-user>/customer-files`
