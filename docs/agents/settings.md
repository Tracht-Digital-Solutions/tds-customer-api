# Runtime service config (`AppSettings` + `/admin/settings`)

Third-party config that isn't installation-relevant is edited **at runtime** from the admin UI, not
baked into `.env` by the installer:

| Section | Keys |
|---|---|
| Stripe | `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PUBLIC_KEY`, `STRIPE_RETURN_URL` |
| Ticket mailer (SMTP) | `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_SECURITY`, `SMTP_FROM`, `TICKET_ADMIN_EMAIL`, `TICKET_INBOX_ADDRESS` |
| IMAP inbox | `IMAP_HOST`, `IMAP_PORT`, `IMAP_USER`, `IMAP_PASSWORD`, `IMAP_SECURITY`, `IMAP_FOLDER`, `INGEST_TOKEN` |
| Lexware | `LEXWARE_API_KEY`, `LEXWARE_API_URL`, `LEXWARE_DEFAULT_HOURLY_RATE`, `LEXWARE_TAX_RATE_PERCENT` |

## `Service\AppSettings`

- Reads and writes `app_setting`; `setting_key` equals the env var name.
- **Precedence:** a non-empty DB value, else the env var (safe `?? false` precedence), else the coded
  default. Existing `.env` deployments keep working, and a blank DB row never shadows an env var.
- **Secrets are encrypted at rest** (`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `SMTP_PASSWORD`,
  `IMAP_PASSWORD`, `INGEST_TOKEN`, `LEXWARE_API_KEY`): AES-256-GCM under `SETTINGS_ENCRYPTION_KEY`, stored
  as `gcm:base64(iv|tag|ciphertext)`. `deriveKey()` hashes the secret to 32 bytes. Unset key → plaintext
  fallback with `encryptionAvailable=false` (dev only).

## Routes (behind the admin JWT)

- **`GET /admin/settings`** returns the masked, section-grouped state: `configured` / `last4` / `source`
  for secrets, full `value` otherwise, plus `encryptionAvailable`. Never a raw secret.
- **`PUT /admin/settings`** takes a flat `{KEY: value}` map. A **blank secret keeps the existing
  value**; a blank non-secret clears the override.

## Consumers read DB-first, lazily

`PayAction` / `WebhookAction` take `AppSettings` in the constructor; `TicketMailer` and the Lexware
factories resolve it inside lazy container factories; `HealthAction` gets a `\Closure(): AppSettings`
and its `checkStripe()` reports configured when DB **or** env has the key, in try/catch.

Boot stays DB-free: nothing resolves at `createApp()`, so `/healthz` survives a DB outage.
`dbValues()` swallows a query failure (un-migrated table mid-deploy) and falls back to env.
