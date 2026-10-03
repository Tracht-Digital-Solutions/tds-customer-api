<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Opens the ticket model to contact-form submissions from people who are NOT
 * customers (see Action\Ticket\ContactIngestAction, fed by tds-contact-api):
 *
 *  - customer_id becomes NULLABLE — a contact ticket has no owning customer
 *    (the FK stays; NULL rows are simply never matched by the customer portal,
 *    so such tickets are admin-only). When a submitter's email happens to match
 *    a customer, the ticket is still bound to that customer.
 *  - from_name / from_email / from_company hold the submitter's contact details
 *    structurally (not buried in the description); from_email is indexed so an
 *    email reply from a non-customer can thread back onto their contact ticket.
 *  - source gains 'contact' (provenance, alongside 'portal' | 'email').
 *  - type gains 'contact' — contact tickets are categorised separately from
 *    support tickets (question|bug|feature|other) in the admin UI.
 *
 * Explicit up()/down() (not change()) because the enum extensions + the
 * customer_id nullability flip are not auto-reversible. Written MySQL-8-safe —
 * prod is MySQL 8, stricter than dev/CI MariaDB. Service-prefixed class name on
 * purpose: the gateway's in-process auto-migrate loads every service's
 * migrations into ONE PHP process, so class names must be unique across ALL four
 * API services or the second include is an uncatchable fatal redeclaration.
 */
final class CustomerAddTicketContactFields extends AbstractMigration
{
    public function up(): void
    {
        // FK on customer_id stays; only the nullability changes.
        $this->execute('ALTER TABLE ticket MODIFY customer_id INT UNSIGNED NULL');

        $this->table('ticket')
            ->addColumn('from_name', 'string', ['limit' => 200, 'null' => true, 'after' => 'closed_at'])
            ->addColumn('from_email', 'string', ['limit' => 254, 'null' => true, 'after' => 'from_name'])
            ->addColumn('from_company', 'string', ['limit' => 200, 'null' => true, 'after' => 'from_email'])
            ->addIndex(['from_email'], ['name' => 'idx_from_email'])
            ->update();

        $this->execute(
            "ALTER TABLE ticket MODIFY source ENUM('portal','email','contact') NOT NULL DEFAULT 'portal'"
        );
        $this->execute(
            "ALTER TABLE ticket MODIFY type ENUM('question','bug','feature','other','contact') NOT NULL DEFAULT 'question'"
        );
    }

    public function down(): void
    {
        // Remove rows that only the widened schema can hold, so the tightened
        // constraints below apply cleanly.
        $this->execute('DELETE FROM ticket WHERE customer_id IS NULL');
        $this->execute("UPDATE ticket SET type = 'other' WHERE type = 'contact'");
        $this->execute("UPDATE ticket SET source = 'portal' WHERE source = 'contact'");

        $this->execute(
            "ALTER TABLE ticket MODIFY type ENUM('question','bug','feature','other') NOT NULL DEFAULT 'question'"
        );
        $this->execute(
            "ALTER TABLE ticket MODIFY source ENUM('portal','email') NOT NULL DEFAULT 'portal'"
        );

        $this->table('ticket')
            ->removeIndexByName('idx_from_email')
            ->removeColumn('from_company')
            ->removeColumn('from_email')
            ->removeColumn('from_name')
            ->update();

        $this->execute('ALTER TABLE ticket MODIFY customer_id INT UNSIGNED NOT NULL');
    }
}
