<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTimeEntry extends AbstractMigration
{
    public function change(): void
    {
        $this->table('time_entry', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('project_id', 'integer')
            ->addColumn('milestone_id', 'integer', ['null' => true])
            ->addColumn('started_at', 'datetime')
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addColumn('duration_minutes', 'integer', ['null' => true])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('source', 'enum', [
                'values' => ['manual', 'timer'],
                'default' => 'manual',
            ])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['project_id', 'started_at'], ['name' => 'idx_project_started'])
            ->addIndex(['milestone_id'], ['name' => 'idx_milestone'])
            ->addIndex(['ended_at'], ['name' => 'idx_ended_at'])
            ->addForeignKey('project_id', 'project', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('milestone_id', 'milestone', 'id', ['delete' => 'SET NULL'])
            ->create();
    }
}
