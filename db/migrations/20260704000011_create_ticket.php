<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A support ticket raised by a customer (or opened by an admin on their behalf).
 * `status_id` points at the configurable ticket_status registry (RESTRICT so a
 * status in use can't be deleted). `assignee_user_id` / `created_by_user_id`
 * reference tds-auth-api `app_user.id` and deliberately carry NO foreign key —
 * admins live in a different service/DB.
 *
 * `customer_action_required` + `customer_action_note` are the admin-set "waiting
 * for customer" prompt shown prominently in the portal; a customer reply clears
 * the flag.
 */
final class CreateTicket extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ticket', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // FK columns UNSIGNED to match phinx's auto-increment `id` (MySQL 8
            // rejects a signed→unsigned FK, error 3780; MariaDB tolerated it).
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status_id', 'integer', ['signed' => false])
            ->addColumn('subject', 'string', ['limit' => 200])
            ->addColumn('description', 'text')
            ->addColumn('priority', 'enum', [
                'values' => ['low', 'normal', 'high', 'urgent'],
                'default' => 'normal',
            ])
            ->addColumn('type', 'enum', [
                'values' => ['question', 'bug', 'feature', 'other'],
                'default' => 'question',
            ])
            // References tds-auth-api app_user.id — no FK (different service/DB).
            ->addColumn('assignee_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_by_type', 'enum', ['values' => ['customer', 'owner']])
            ->addColumn('created_by_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('customer_action_required', 'boolean', ['default' => false])
            ->addColumn('customer_action_note', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('closed_at', 'datetime', ['null' => true])
            ->addIndex(['customer_id', 'updated_at'], ['name' => 'idx_customer_updated'])
            ->addIndex(['status_id'], ['name' => 'idx_status'])
            ->addIndex(['assignee_user_id'], ['name' => 'idx_assignee'])
            ->addForeignKey('customer_id', 'customer', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('project_id', 'project', 'id', ['delete' => 'SET NULL'])
            ->addForeignKey('status_id', 'ticket_status', 'id', ['delete' => 'RESTRICT'])
            ->create();
    }
}
