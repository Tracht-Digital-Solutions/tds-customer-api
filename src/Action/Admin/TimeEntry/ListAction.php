<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * GET /admin/time-entries — list time entries. Filters via query
 * string: projectId, customerId, from (YYYY-MM-DD), to (YYYY-MM-DD),
 * includeRunning (=1 to include the running timer row).
 */
final class ListAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $q = $request->getQueryParams();

        $where = [];
        $params = [];

        if (isset($q['projectId']) && ctype_digit((string) $q['projectId'])) {
            $where[] = 'te.project_id = :pid';
            $params['pid'] = (int) $q['projectId'];
        }
        if (isset($q['customerId']) && ctype_digit((string) $q['customerId'])) {
            $where[] = 'p.customer_id = :cid';
            $params['cid'] = (int) $q['customerId'];
        }
        if (isset($q['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q['from']) === 1) {
            $where[] = 'te.started_at >= :from';
            $params['from'] = $q['from'] . ' 00:00:00';
        }
        if (isset($q['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q['to']) === 1) {
            $where[] = 'te.started_at <= :to';
            $params['to'] = $q['to'] . ' 23:59:59';
        }
        if (!isset($q['includeRunning']) || (string) $q['includeRunning'] !== '1') {
            $where[] = 'te.ended_at IS NOT NULL';
        }

        $sql = 'SELECT te.id, te.project_id, te.milestone_id, te.started_at, te.ended_at, '
            . 'te.duration_minutes, te.description, te.source, '
            . 'p.title AS project_title, p.customer_id, m.title AS milestone_title '
            . 'FROM time_entry te '
            . 'INNER JOIN project p ON p.id = te.project_id '
            . 'LEFT JOIN milestone m ON m.id = te.milestone_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY te.started_at DESC, te.id DESC LIMIT 500';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->json($response, 200, ['entries' => $stmt->fetchAll()]);
    }
}
