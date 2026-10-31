# Tickets

Customers use `/tickets` (open, comment, attach); admins use `/admin/tickets` (assign to a support agent,
set priority and type, move through statuses, add internal notes, set "customer action required").
Support agents are admins with `is_support_agent` in `tds-auth-api`; the frontend fetches them from
auth-api `/admin/users`.

## Statuses

Runtime-configurable (`ticket_status`), not an ENUM. Each has a chip `color`
(neutral | info | success | warning | danger), `visible_to_customer`, `is_terminal` (closing stamps
`closed_at`) and one `is_default` (new tickets start there).

When a status isn't visible to customers, `TicketRepository::present(…, forCustomer: true)` swaps in a
neutral "In Bearbeitung", so internal stages never leak.

## Read model

`TicketRepository` owns reads (joins the status registry, applies per-audience visibility), so every
endpoint agrees. `TicketStatusRepository` owns the registry, `TicketSettings` the notification toggles.
**Internal notes** (`ticket_comment.is_internal`) are returned only to admins (`includeInternal: false`
on customer paths).

## Email notifications

`TicketMailer` → `SmtpMailer`. Opt-in per event via `ticket_setting` toggles, and a no-op when SMTP is
unconfigured (`SMTP_HOST` / `SMTP_FROM` empty). A failed send never breaks the ticket write.

- New ticket → admin inbox (`TICKET_ADMIN_EMAIL`).
- Visible status change or public reply → customer.
- Customer-facing mail carries `Reply-To = TICKET_INBOX_ADDRESS` (the IMAP inbox) and the `#<id>`
  subject marker, so a reply threads back.
- `TicketRepository::notifyEmail()` resolves the recipient as the customer email **or** the
  submitter's `from_email`.

## Inbound email → tickets (`ImapTicketIngest`)

One `poll()` connects to the IMAP mailbox, fetches UNSEEN mail and per message:

1. resolves the sender via `customer.email` (**unknown senders are skipped**, never ticketed),
2. dedupes on `Message-ID`,
3. threads onto an existing ticket via the `#<id>` marker or an `In-Reply-To` / `References` match
   belonging to that sender, else opens a `source='email'` ticket,
4. stores allowed attachments (`AttachmentStorage::storeBytes`),
5. marks the message `\Seen`.

webklex/php-imap uses stream sockets (no `ext-imap`, no `proc_open`), so it runs in-process. **There is
no worker on prod:** `poll()` is driven by an external scheduler calling the secret-gated
`POST /tickets/ingest` (`INGEST_TOKEN`; see `.github/workflows/imap-poll.yml` or a Plesk scheduled
task) and by `POST /admin/tickets/ingest` ("Jetzt abrufen"). `GET /admin/tickets/imap-test` backs
"Verbindung testen".

## Contact form → tickets (`POST /tickets/contact`, `ContactIngestAction`)

Server-to-server with the same `INGEST_TOKEN`, no JWT. Opens a `type='contact'`, `source='contact'`
ticket with the submitter's details in `from_name` / `from_email` / `from_company`.

- `customer_id` is **nullable**: a contact ticket without a customer is admin-only (the portal lists
  strictly by `customer_id`). If the email matches `customer.email`, the ticket binds to that customer.
- The admin list `LEFT JOIN`s customer and falls back to `from_*`; a `type` filter separates contact
  from support tickets.
- A contact submitter's email reply threads back via `findContactTicketForReply()` (`from_email` +
  `#<id>`). New mail from an unknown sender is still dropped (anti-spam).

## Attachments (`Service\AttachmentStorage`)

- **Filenames are sanitised** before use as a path segment; `../../etc/passwd` must not escape the
  customer's directory.
- A **MIME allow-list** (no `text/html`, no `image/svg+xml`) and a **25 MB cap** (exact boundary)
  decide what is stored.
- Files live per customer (`{customer}/tickets/…`, which the download route authorises against) with a
  UUID, so same-name uploads don't overwrite.
- `storeBytes()` (IMAP path) returns **null instead of throwing**; one bad MIME part must not fail a
  whole email.
