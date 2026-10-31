<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateDocument extends AbstractMigration
{
    public function change(): void
    {
        $this->table('document', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // FK columns UNSIGNED to match phinx's auto-increment `id` (MySQL 8
            // rejects a signed→unsigned FK, error 3780; MariaDB tolerated it).
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('filename', 'string', ['limit' => 255])
            ->addColumn('storage_path', 'string', ['limit' => 500])
            ->addColumn('mime_type', 'string', ['limit' => 100])
            ->addColumn('size_bytes', 'biginteger')
            ->addColumn('uploaded_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['customer_id'], ['name' => 'idx_customer_id'])
            ->addIndex(['project_id'], ['name' => 'idx_project_id'])
            ->addForeignKey('customer_id', 'customer', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('project_id', 'project', 'id', ['delete' => 'SET NULL'])
            ->create();
    }
}
