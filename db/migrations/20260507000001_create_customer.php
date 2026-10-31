<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCustomer extends AbstractMigration
{
    public function change(): void
    {
        $this->table('customer', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('email', 'string', ['limit' => 254])
            ->addColumn('name', 'string', ['limit' => 200])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uniq_email'])
            ->create();
    }
}
