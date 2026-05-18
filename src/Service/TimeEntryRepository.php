<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use PDO;

/**
 * Thin SQL helper for `time_entry`. Other resources inline their
 * queries into the action; time entries get a helper because the
 * timer flow (one currently-running row at a time) is shared between
 * three actions, and centralising the "running entry" lookup avoids
 * three slightly-different LIMIT clauses drifting apart.
 *
 * `runningEntry()` returns the at-most-one row with `ended_at IS NULL`.
 * Concurrent starts are prevented in the action by checking this
 * before inserting; we don't enforce it as a DB constraint because a
 * partial unique index would need MySQL 8 + a generated column, and
 * the single-admin scenario doesn't justify it.
 */
final class TimeEntryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function runningEntry(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM time_entry WHERE ended_at IS NULL ORDER BY started_at DESC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM time_entry WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function projectBelongsToCustomer(int $projectId, int $customerId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM project WHERE id = :pid AND customer_id = :cid LIMIT 1'
        );
        $stmt->execute(['pid' => $projectId, 'cid' => $customerId]);
        return $stmt->fetchColumn() !== false;
    }

    public function milestoneBelongsToProject(int $milestoneId, int $projectId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM milestone WHERE id = :mid AND project_id = :pid LIMIT 1'
        );
        $stmt->execute(['mid' => $milestoneId, 'pid' => $projectId]);
        return $stmt->fetchColumn() !== false;
    }

    public function projectExists(int $projectId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM project WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $projectId]);
        return $stmt->fetchColumn() !== false;
    }

    public function insertManual(
        int $projectId,
        ?int $milestoneId,
        string $startedAt,
        string $endedAt,
        int $durationMinutes,
        string $description,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entry '
            . '(project_id, milestone_id, started_at, ended_at, duration_minutes, description, source) '
            . "VALUES (:pid, :mid, :sa, :ea, :dur, :desc, 'manual')"
        );
        $stmt->execute([
            'pid' => $projectId,
            'mid' => $milestoneId,
            'sa' => $startedAt,
            'ea' => $endedAt,
            'dur' => $durationMinutes,
            'desc' => $description,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        ?int $milestoneId,
        string $startedAt,
        string $endedAt,
        int $durationMinutes,
        string $description,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE time_entry SET milestone_id = :mid, started_at = :sa, ended_at = :ea, '
            . 'duration_minutes = :dur, description = :desc WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'mid' => $milestoneId,
            'sa' => $startedAt,
            'ea' => $endedAt,
            'dur' => $durationMinutes,
            'desc' => $description,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM time_entry WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function startTimer(int $projectId, ?int $milestoneId, string $description): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entry '
            . '(project_id, milestone_id, started_at, description, source) '
            . "VALUES (:pid, :mid, NOW(), :desc, 'timer')"
        );
        $stmt->execute([
            'pid' => $projectId,
            'mid' => $milestoneId,
            'desc' => $description,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function stopTimer(int $id, string $endedAt, int $durationMinutes, ?string $description): void
    {
        $sql = 'UPDATE time_entry SET ended_at = :ea, duration_minutes = :dur';
        $params = ['id' => $id, 'ea' => $endedAt, 'dur' => $durationMinutes];
        if ($description !== null) {
            $sql .= ', description = :desc';
            $params['desc'] = $description;
        }
        $sql .= ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
