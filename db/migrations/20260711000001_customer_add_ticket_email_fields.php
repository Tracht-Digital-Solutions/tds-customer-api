<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds the email-channel columns that let inbound IMAP mail become / append to a
 * support ticket (see Service\ImapTicketIngest):
 *
 *  - ticket.source            — 'portal' (default, in-app) | 'email' (IMAP-ingested)
 *  - ticket.email_message_id  — Message-ID of the mail that opened the ticket
 *  - ticket_comment.email_message_id — Message-ID of the mail that produced a reply
 *
 * The two email_message_id columns are what the poller dedupes re-delivered mail
 * against and matches In-Reply-To / References headers to, so a customer's reply
 * threads onto the right ticket.
 *
 * Service-prefixed class name on purpose: the gateway's in-process auto-migrate
 * loads every service's migrations into ONE PHP process, so migration class
 * names must be unique across ALL four API services or the second include is an
 * uncatchable fatal redeclaration. Written MySQL-8-safe (nullable columns, no
 * nullable PK) — prod is MySQL 8, stricter than dev/CI MariaDB.
 */
final class CustomerAddTicketEmailFields extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ticket')
            ->addColumn('source', 'enum', [
                'values' => ['portal', 'email'],
                'default' => 'portal',
                'after' => 'created_by_user_id',
            ])
            ->addColumn('email_message_id', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'source',
            ])
            ->addIndex(['email_message_id'], ['name' => 'idx_email_message_id'])
            ->update();

        $this->table('ticket_comment')
            ->addColumn('email_message_id', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'is_internal',
            ])
            ->addIndex(['email_message_id'], ['name' => 'idx_comment_email_message_id'])
            ->update();
    }
}
