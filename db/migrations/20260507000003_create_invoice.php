<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateInvoice extends AbstractMigration
{
    public function change(): void
    {
        $this->table('invoice', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // FK columns UNSIGNED to match phinx's auto-increment `id` (MySQL 8
            // rejects a signed→unsigned FK, error 3780; MariaDB tolerated it).
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('amount_cents', 'integer')
            ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'EUR'])
            ->addColumn('status', 'enum', [
                'values' => ['open', 'paid', 'void'],
                'default' => 'open',
            ])
            ->addColumn('due_date', 'date')
            ->addColumn('stripe_invoice_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('stripe_payment_intent_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('paid_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['customer_id'], ['name' => 'idx_customer_id'])
            ->addIndex(['stripe_invoice_id'], ['name' => 'idx_stripe_invoice_id'])
            ->addForeignKey('customer_id', 'customer', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('project_id', 'project', 'id', ['delete' => 'SET NULL'])
            ->create();
    }
}
