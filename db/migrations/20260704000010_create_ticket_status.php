<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Admin-configurable ticket status registry. Unlike project.status (a fixed
 * ENUM), ticket statuses are edited at runtime in tds-admin: label, colour
 * tone, order, whether the customer sees the real label (`visible_to_customer`)
 * or a neutral fallback, whether it closes the ticket (`is_terminal`), and which
 * status new tickets start in (`is_default`). `color` is one of the design
 * system's chip tones: neutral | info | success | warning | danger.
 *
 * Seeds the five defaults from the product spec so a fresh install has a working
 * workflow. Uses up()/down() (not change()) because of the seed inserts.
 */
final class CreateTicketStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ticket_status', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('name', 'string', ['limit' => 80])
            ->addColumn('color', 'string', ['limit' => 20, 'default' => 'neutral'])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('visible_to_customer', 'boolean', ['default' => true])
            ->addColumn('is_terminal', 'boolean', ['default' => false])
            ->addColumn('is_default', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['sort_order'], ['name' => 'idx_sort_order'])
            ->create();

        $this->table('ticket_status')->insert([
            ['name' => 'Offen',            'color' => 'warning', 'sort_order' => 10, 'visible_to_customer' => 1, 'is_terminal' => 0, 'is_default' => 1],
            ['name' => 'In Bearbeitung',   'color' => 'info',    'sort_order' => 20, 'visible_to_customer' => 1, 'is_terminal' => 0, 'is_default' => 0],
            ['name' => 'Warten auf Kunde', 'color' => 'warning', 'sort_order' => 30, 'visible_to_customer' => 1, 'is_terminal' => 0, 'is_default' => 0],
            ['name' => 'Intern prüfen',    'color' => 'neutral', 'sort_order' => 40, 'visible_to_customer' => 0, 'is_terminal' => 0, 'is_default' => 0],
            ['name' => 'Gelöst',           'color' => 'success', 'sort_order' => 50, 'visible_to_customer' => 1, 'is_terminal' => 1, 'is_default' => 0],
        ])->save();
    }

    public function down(): void
    {
        $this->table('ticket_status')->drop()->save();
    }
}
