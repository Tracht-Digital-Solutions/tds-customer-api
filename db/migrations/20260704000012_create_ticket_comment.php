<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A comment on a ticket — the customer↔admin conversation (near-clone of the
 * `message` table). `author_type` is derived from the JWT (`owner` when
 * admin=true, else `customer`), never trusted from the client. `is_internal`
 * marks an admin-only note that is never returned to a customer principal.
 */
final class CreateTicketComment extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ticket_comment', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('ticket_id', 'integer', ['signed' => false])
            ->addColumn('author_type', 'enum', ['values' => ['customer', 'owner']])
            // References tds-auth-api app_user.id — no FK (different service/DB).
            ->addColumn('author_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('body', 'text')
            ->addColumn('is_internal', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('edited_at', 'datetime', ['null' => true])
            ->addIndex(['ticket_id', 'created_at'], ['name' => 'idx_ticket_created'])
            ->addForeignKey('ticket_id', 'ticket', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
