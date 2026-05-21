<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Service\TimeEntryRepository;

/**
 * Integration test against MariaDB. Set TDS_TEST_DB_DSN to run.
 */
final class TimeEntryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private TimeEntryRepository $repo;

    protected function setUp(): void
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run time entry repository tests.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('TDS_TEST_DB_USER') ?: null,
            getenv('TDS_TEST_DB_PASS') ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        // Order matters: drop dependent tables first to satisfy FKs.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS time_entry');
        $this->pdo->exec('DROP TABLE IF EXISTS milestone');
        $this->pdo->exec('DROP TABLE IF EXISTS project');
        $this->pdo->exec('DROP TABLE IF EXISTS customer');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE customer (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              email VARCHAR(254) NOT NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE project (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              customer_id INT NOT NULL,
              title VARCHAR(200) NOT NULL,
              PRIMARY KEY (id),
              KEY (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE milestone (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              project_id INT NOT NULL,
              title VARCHAR(200) NOT NULL,
              PRIMARY KEY (id),
              KEY (project_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE time_entry (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              project_id INT NOT NULL,
              milestone_id INT NULL,
              started_at DATETIME NOT NULL,
              ended_at DATETIME NULL,
              duration_minutes INT NULL,
              description TEXT NULL,
              source ENUM('manual', 'timer') NOT NULL DEFAULT 'manual',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_ended_at (ended_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec("INSERT INTO customer (id, email) VALUES (1, 'c1@example.com'), (2, 'c2@example.com')");
        $this->pdo->exec("INSERT INTO project (id, customer_id, title) VALUES (10, 1, 'P10'), (11, 2, 'P11')");
        $this->pdo->exec("INSERT INTO milestone (id, project_id, title) VALUES (100, 10, 'M100')");

        $this->repo = new TimeEntryRepository($this->pdo);
    }

    public function test_running_entry_returns_null_when_none(): void
    {
        self::assertNull($this->repo->runningEntry());
    }

    public function test_running_entry_returns_open_row(): void
    {
        $id = $this->repo->startTimer(projectId: 10, milestoneId: null, description: 'working');

        $row = $this->repo->runningEntry();
        self::assertNotNull($row);
        self::assertSame($id, (int) $row['id']);
        self::assertNull($row['ended_at']);
    }

    public function test_stop_timer_sets_ended_at_and_duration(): void
    {
        $id = $this->repo->startTimer(10, null, 'first');
        $endedAt = date('Y-m-d H:i:s');

        $this->repo->stopTimer($id, $endedAt, 30, 'final note');

        $row = $this->repo->findById($id);
        self::assertNotNull($row);
        self::assertSame($endedAt, $row['ended_at']);
        self::assertSame(30, (int) $row['duration_minutes']);
        self::assertSame('final note', $row['description']);
    }

    public function test_stop_timer_without_description_keeps_existing(): void
    {
        $id = $this->repo->startTimer(10, null, 'original');

        $this->repo->stopTimer($id, date('Y-m-d H:i:s'), 15, null);

        $row = $this->repo->findById($id);
        self::assertSame('original', $row['description']);
    }

    public function test_project_belongs_to_customer(): void
    {
        self::assertTrue($this->repo->projectBelongsToCustomer(10, 1));
        self::assertFalse($this->repo->projectBelongsToCustomer(10, 2));
        self::assertFalse($this->repo->projectBelongsToCustomer(999, 1));
    }

    public function test_milestone_belongs_to_project(): void
    {
        self::assertTrue($this->repo->milestoneBelongsToProject(100, 10));
        self::assertFalse($this->repo->milestoneBelongsToProject(100, 11));
        self::assertFalse($this->repo->milestoneBelongsToProject(999, 10));
    }

    public function test_insert_manual_persists_all_fields(): void
    {
        $id = $this->repo->insertManual(
            projectId: 10,
            milestoneId: 100,
            startedAt: '2026-05-01 09:00:00',
            endedAt: '2026-05-01 10:30:00',
            durationMinutes: 90,
            description: 'design review',
        );

        $row = $this->repo->findById($id);
        self::assertSame(10, (int) $row['project_id']);
        self::assertSame(100, (int) $row['milestone_id']);
        self::assertSame(90, (int) $row['duration_minutes']);
        self::assertSame('manual', $row['source']);
    }

    public function test_update_changes_fields(): void
    {
        $id = $this->repo->insertManual(10, null, '2026-05-01 09:00:00', '2026-05-01 09:30:00', 30, 'before');

        $this->repo->update($id, 100, '2026-05-01 10:00:00', '2026-05-01 11:00:00', 60, 'after');

        $row = $this->repo->findById($id);
        self::assertSame(100, (int) $row['milestone_id']);
        self::assertSame(60, (int) $row['duration_minutes']);
        self::assertSame('after', $row['description']);
    }

    public function test_delete_removes_row(): void
    {
        $id = $this->repo->insertManual(10, null, '2026-05-01 09:00:00', '2026-05-01 09:30:00', 30, '');

        $this->repo->delete($id);

        self::assertNull($this->repo->findById($id));
    }
}
