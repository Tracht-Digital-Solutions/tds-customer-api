<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use PDO;

/**
 * The admin-configurable ticket_status registry. Statuses drive the ticket
 * workflow: their `visible_to_customer` flag decides whether the customer sees
 * the real label or a neutral fallback, and `is_terminal` marks a status that
 * closes the ticket. Colours are one of the design-system chip tones
 * (neutral | info | success | warning | danger).
 */
final class TicketStatusRepository
{
    public const COLORS = ['neutral', 'info', 'success', 'warning', 'danger'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, color, sort_order, visible_to_customer, is_terminal, is_default '
            . 'FROM ticket_status ORDER BY sort_order ASC, id ASC'
        );
        return $stmt === false ? [] : array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, color, sort_order, visible_to_customer, is_terminal, is_default '
            . 'FROM ticket_status WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /** The status new tickets start in: the flagged default, else the first by order. */
    public function defaultId(): ?int
    {
        $stmt = $this->pdo->query(
            'SELECT id FROM ticket_status ORDER BY is_default DESC, sort_order ASC, id ASC LIMIT 1'
        );
        $id = $stmt === false ? false : $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function isInUse(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ticket WHERE status_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM ticket_status');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        // A newly-flagged default demotes the previous one (single default).
        if (!empty($data['is_default'])) {
            $this->pdo->exec('UPDATE ticket_status SET is_default = 0');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_status (name, color, sort_order, visible_to_customer, is_terminal, is_default) '
            . 'VALUES (:name, :color, :sort, :vis, :term, :def)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'color' => $data['color'],
            'sort' => $data['sort_order'],
            'vis' => $data['visible_to_customer'] ? 1 : 0,
            'term' => $data['is_terminal'] ? 1 : 0,
            'def' => !empty($data['is_default']) ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        if (!empty($data['is_default'])) {
            $this->pdo->exec('UPDATE ticket_status SET is_default = 0');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ticket_status SET name = :name, color = :color, sort_order = :sort, '
            . 'visible_to_customer = :vis, is_terminal = :term, is_default = :def WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'color' => $data['color'],
            'sort' => $data['sort_order'],
            'vis' => $data['visible_to_customer'] ? 1 : 0,
            'term' => $data['is_terminal'] ? 1 : 0,
            'def' => !empty($data['is_default']) ? 1 : 0,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ticket_status WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Present a status for a customer: the real status when visible, otherwise a
     * neutral "In Bearbeitung" fallback so internal workflow stages never leak.
     *
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public static function presentForCustomer(array $status): array
    {
        if ($status['visibleToCustomer'] === true) {
            return $status;
        }
        return [
            'id' => $status['id'],
            'name' => 'In Bearbeitung',
            'color' => 'info',
            'visibleToCustomer' => false,
            'isTerminal' => (bool) $status['isTerminal'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'color' => (string) $row['color'],
            'sortOrder' => (int) $row['sort_order'],
            'visibleToCustomer' => (bool) $row['visible_to_customer'],
            'isTerminal' => (bool) $row['is_terminal'],
            'isDefault' => (bool) $row['is_default'],
        ];
    }
}
