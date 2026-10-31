<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProject extends AbstractMigration
{
    public function change(): void
    {
        $this->table('project', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // FK columns must be UNSIGNED to match phinx's auto-increment `id`
            // (int unsigned). MySQL 8 rejects a signed→unsigned FK (error 3780);
            // MariaDB tolerated it, which hid this until a MySQL 8 install.
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('status', 'enum', [
                'values' => ['discovery', 'in_progress', 'review', 'delivered', 'on_hold'],
                'default' => 'discovery',
            ])
            ->addColumn('start_date', 'date', ['null' => true])
            ->addColumn('target_date', 'date', ['null' => true])
            ->addColumn('description', 'text')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['customer_id'], ['name' => 'idx_customer_id'])
            ->addForeignKey('customer_id', 'customer', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('milestone', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('project_id', 'integer', ['signed' => false])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'in_progress', 'completed'],
                'default' => 'pending',
            ])
            ->addColumn('due_date', 'date', ['null' => true])
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addIndex(['project_id'], ['name' => 'idx_project_id'])
            ->addForeignKey('project_id', 'project', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
