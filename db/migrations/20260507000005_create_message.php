<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMessage extends AbstractMigration
{
    public function change(): void
    {
        $this->table('message', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // FK columns UNSIGNED to match phinx's auto-increment `id` (MySQL 8
            // rejects a signed→unsigned FK, error 3780; MariaDB tolerated it).
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('author_type', 'enum', [
                'values' => ['customer', 'owner'],
            ])
            ->addColumn('body', 'text')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('read_at', 'datetime', ['null' => true])
            ->addIndex(['customer_id', 'created_at'], ['name' => 'idx_customer_created'])
            ->addIndex(['project_id', 'created_at'], ['name' => 'idx_project_created'])
            ->addForeignKey('customer_id', 'customer', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('project_id', 'project', 'id', ['delete' => 'SET NULL'])
            ->create();
    }
}
