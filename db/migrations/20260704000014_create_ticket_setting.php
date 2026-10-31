<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Small key/value store for ticket-system settings — currently the three
 * email-notification toggles the admin controls in tds-admin. Kept as a generic
 * table (no bespoke columns) so future ticket settings can be added without a
 * migration. Values are stored as strings ('true'/'false'). Seeds the three
 * notification toggles off by default (email is opt-in and also no-ops when
 * SMTP is unconfigured). Uses up()/down() for the seed.
 */
final class CreateTicketSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ticket_setting', [
            'id' => false,
            'primary_key' => 'setting_key',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // Explicit NOT NULL: MySQL 8 rejects a nullable PRIMARY KEY column
            // (error 1171), whereas MariaDB silently coerces it. A string PK
            // column isn't emitted NOT NULL on its own, so a fresh install on
            // MySQL 8 failed here without this.
            ->addColumn('setting_key', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('setting_value', 'string', ['limit' => 255])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('ticket_setting')->insert([
            ['setting_key' => 'notify_admin_on_new',      'setting_value' => 'false'],
            ['setting_key' => 'notify_customer_on_status', 'setting_value' => 'false'],
            ['setting_key' => 'notify_customer_on_reply',  'setting_value' => 'false'],
        ])->save();
    }

    public function down(): void
    {
        $this->table('ticket_setting')->drop()->save();
    }
}
