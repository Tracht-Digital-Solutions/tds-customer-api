<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddMessageEditedAt extends AbstractMigration
{
    public function change(): void
    {
        $this->table('message')
            ->addColumn('edited_at', 'datetime', [
                'null' => true,
                'after' => 'read_at',
            ])
            ->update();
    }
}
