<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Generic key/value store for non-installation-relevant third-party service
 * configuration the admin edits at runtime (via the Einrichtungsassistent /
 * Einstellungen in tds-admin) instead of the installer writing it into .env:
 * Stripe, the SMTP ticket mailer, the IMAP inbox, and Lexware. Same generic shape as
 * ticket_setting, but setting_value is TEXT so it can hold base64 AES-256-GCM
 * ciphertext for secret keys.
 *
 * No seed rows on purpose: an absent key means "fall back to the .env value
 * (or the coded default)", so existing .env deployments keep working and a
 * blank row never shadows a configured env var.
 *
 * Service-prefixed class name on purpose: the gateway's in-process auto-migrate
 * loads every service's migrations into ONE PHP process, so migration class
 * names must be unique across ALL services or the second include fatals.
 */
final class CreateCustomerAppSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->table('app_setting', [
            'id' => false,
            'primary_key' => 'setting_key',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // Explicit NOT NULL: MySQL 8 rejects a nullable PRIMARY KEY column
            // (error 1171), whereas MariaDB silently coerces it. Matches the
            // ticket_setting migration.
            ->addColumn('setting_key', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('setting_value', 'text')
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    public function down(): void
    {
        $this->table('app_setting')->drop()->save();
    }
}
