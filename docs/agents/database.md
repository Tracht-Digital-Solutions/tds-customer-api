# Database

## Schema (`db/migrations/`)

| Table | Columns |
|---|---|
| `customer` | id, email UNIQUE, name, created_at, updated_at |
| `project` | id, customer_id FK, title, status, start/target dates, description |
| `milestone` | id, project_id FK, title, status, due/completed dates, sort_order |
| `invoice` | id, customer_id FK, project_id FK?, amount_cents, currency, status, stripe_*, paid_at |
| `document` | id, customer_id FK, project_id FK?, filename, storage_path, mime_type, size_bytes, uploaded_at |
| `message` | id, customer_id FK, project_id FK?, author_type, body, created_at, read_at, edited_at |
| `audit_log` | id, actor_type, actor_id, action, method, path, target_type, target_id, status, ip, created_at |
| `time_entry` | id, project_id FK, milestone_id FK?, started_at, ended_at?, duration_minutes?, description, source, created_at, updated_at |
| `ticket_status` | id, name, color, sort_order, visible_to_customer, is_terminal, is_default, … (5 seeded defaults) |
| `ticket` | id, customer_id FK (nullable), project_id FK?, status_id FK, subject, description, priority, type, assignee_user_id, created_by_type/_user_id, customer_action_required, customer_action_note, source, email_message_id, from_name/from_email/from_company, created_at, updated_at, closed_at |
| `ticket_comment` | id, ticket_id FK, author_type, author_user_id?, body, is_internal, email_message_id, created_at, edited_at |
| `ticket_attachment` | id, ticket_id FK, comment_id FK?, filename, storage_path, mime_type, size_bytes, uploaded_by_type, created_at |
| `ticket_setting` | setting_key PK, setting_value, updated_at |
| `app_setting` | setting_key PK, setting_value TEXT (ciphertext for secrets), updated_at; no seed rows |

## Foreign keys

- Cascade-delete from `customer`.
- `project_id` on invoice / document / message / ticket is `ON DELETE SET NULL`, so deleting a project
  keeps financial, document and communication history.
- `time_entry` and ticket tables cascade from their parent.
- `ticket.status_id` is **RESTRICT** (a status in use can't be deleted).
- `assignee_user_id` / `*_user_id` reference `tds-auth-api` `app_user.id` with **no FK** (other DB).

## Migrations

- **Prefix every migration class with the service name** (e.g. `CreateCustomerAppSetting`). The
  gateway's in-process auto-migrate loads every service's migrations into one PHP process; identical
  class names across APIs were an uncatchable fatal that took the whole API down.
- **Primary-key columns carry `'null' => false`.** MySQL 8 (production) rejects a nullable PRIMARY KEY
  (error 1171); MariaDB (dev, CI, DB tests) silently coerces it. Two guards:
  `tests/Support/MigrationDialectTest` scans `db/migrations` statically, and `_pipeline.yml` applies the
  whole set to an empty `mysql:8` service on every run.
- `phinx.php` falls back to `getenv()`, because PHP's `variables_order` (`GPCS`) leaves `$_ENV` empty
  from the real environment; a real `.env` still wins.

## Time zones: Europe/Berlin, pinned

`Bootstrap::createApp()` pins PHP (`Infrastructure\TimeZone::pinPhp()`) and `Database::connect()` pins
every DB session (`pinSession()`) to Europe/Berlin, matching production. Every `NOW()` /
`CURRENT_TIMESTAMP` column holds Berlin wall-clock time. CLI PHP and CI containers default to UTC.

- It pins a default and converts nothing. A value written with `gmdate()` / `UTC_TIMESTAMP()` stays UTC
  and needs a reader that names the zone. This service writes none today.
- **Never compare the two conventions in one condition.**
- Official MySQL/MariaDB images ship empty time-zone tables, so the named `SET` can fail. A session
  already at Berlin's offset is left alone; any other gets the current offset
  (`tests/Infrastructure/TimeZoneTest`).
- Moving to UTC means converting rows DST-correctly across every service; that is a separate decision.

## Time tracking

`time_entry` backs the admin time tracker (`/admin/time-entries/*`) and the read-only customer
breakdown (`/projects/{id}/time-entries`).

- At most one row has `ended_at IS NULL` (the running timer), enforced in the app via
  `TimeEntryRepository::runningEntry()` rather than a partial unique index.
- `source` is `manual | timer`, set by the entry point.
- Duration is always recomputed server-side from `started_at` → `ended_at`, so client clock skew can't
  poison it.
