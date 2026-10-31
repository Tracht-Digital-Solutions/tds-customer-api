<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A file attached to a ticket (or to one of its comments). Mirrors the
 * `document` storage model: the bytes live on disk under DOCUMENT_ROOT_DIR and
 * only metadata + the relative `storage_path` are stored here. `comment_id` is
 * optional — an attachment can hang off the ticket itself or a specific comment.
 */
final class CreateTicketAttachment extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ticket_attachment', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('ticket_id', 'integer', ['signed' => false])
            ->addColumn('comment_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('filename', 'string', ['limit' => 255])
            ->addColumn('storage_path', 'string', ['limit' => 500])
            ->addColumn('mime_type', 'string', ['limit' => 150])
            ->addColumn('size_bytes', 'integer', ['signed' => false])
            ->addColumn('uploaded_by_type', 'enum', ['values' => ['customer', 'owner']])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['ticket_id', 'created_at'], ['name' => 'idx_ticket_created'])
            ->addForeignKey('ticket_id', 'ticket', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('comment_id', 'ticket_comment', 'id', ['delete' => 'SET NULL'])
            ->create();
    }
}
