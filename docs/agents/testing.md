# Testing

PHPUnit 10; `composer test` runs the suite. INSTALL.md §6 has the throwaway-Docker test DB recipe.

## Pure unit

- `DocumentSigner` — HMAC round trip; tamper, cross-customer and wrong-secret rejection; expiry.
- `BaseAction` — claim-extraction `LogicException` paths and admin `X-Act-As-Customer` scoping (header
  wins, own-customer fallback, 400 when neither, a non-admin's header ignored).
- `AdminAuthMiddleware`, `JwksAuthMiddleware` (with `tests/Support/FakeTokenVerifier`).
- `tests/PreflightTest.php` — OPTIONS through the real app (CORS order).
- `tests/Support/MigrationDialectTest` — static scan for nullable primary keys.
- `tests/Infrastructure/TimeZoneTest` — session time-zone pinning.
- `ImapTicketIngestParseTest` — pure message-parsing helpers.
- `tests/Service/AttachmentStorageTest.php` — path sanitising (structurally and by resolving the
  written file), MIME allow-list without HTML/SVG, the 25 MB boundary, per-customer layout, UUIDs, and
  `storeBytes()` rejections as `null` rather than exceptions. Mutation check: 17/17 caught.

## Integration (real MariaDB)

Set `TDS_TEST_DB_DSN` (+ `_USER` / `_PASS`); otherwise these skip. Tests drop and recreate the tables
they touch, so don't run `composer migrate` against the test DB.

- `TimeEntryRepository` — timer and manual flows, ownership checks.
- `AuditLogMiddleware` — actor, target and IP recording; graceful failure without `audit_log`.
- `Action\Project\ListAction` — cross-tenant isolation.
- `Action\Ticket\TicketActionsTest` — create/list, customer-visibility fallback, cross-tenant 404, reply
  clears the action flag, internal notes hidden from customers, terminal status closes, assign +
  filter, deleting a status in use → 409.
- `ImapTicketIngestTest`, `ContactIngestActionTest` — ingest DB behaviour, incl. a contact sender's reply
  threading onto their ticket.

A live IMAP fetch is a manual check.

CI (`_pipeline.yml`) also applies all migrations to an empty `mysql:8` service on every run.
