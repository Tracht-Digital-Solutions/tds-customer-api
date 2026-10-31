<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAuditLog extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('audit_log', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn('actor_type', 'string', ['limit' => 16, 'comment' => 'customer | admin'])
            ->addColumn('actor_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('action', 'string', ['limit' => 8, 'comment' => 'read | write'])
            ->addColumn('method', 'string', ['limit' => 8])
            ->addColumn('path', 'string', ['limit' => 255])
            ->addColumn('target_type', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('target_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status', 'smallinteger', ['signed' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true, 'comment' => 'IPv4 or IPv6'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['actor_type', 'actor_id'])
            ->addIndex(['created_at'])
            ->create();
    }
}
