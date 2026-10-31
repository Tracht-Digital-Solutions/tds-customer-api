<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\TimeEntry;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * GET /projects/{id}/time-entries — read-only list of finished time
 * entries on a customer's own project. Running entries (ended_at IS
 * NULL) are excluded so the customer never sees an in-progress timer.
 */
final class ListAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $projectId = (int) ($args['id'] ?? 0);

        $own = $this->pdo->prepare(
            'SELECT 1 FROM project WHERE id = :pid AND customer_id = :cid LIMIT 1'
        );
        $own->execute(['pid' => $projectId, 'cid' => $customerId]);
        if ($own->fetchColumn() === false) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $stmt = $this->pdo->prepare(
            'SELECT te.id, te.project_id, te.milestone_id, te.started_at, te.ended_at, '
            . 'te.duration_minutes, te.description, m.title AS milestone_title '
            . 'FROM time_entry te '
            . 'LEFT JOIN milestone m ON m.id = te.milestone_id '
            . 'WHERE te.project_id = :pid AND te.ended_at IS NOT NULL '
            . 'ORDER BY te.started_at DESC, te.id DESC'
        );
        $stmt->execute(['pid' => $projectId]);
        $entries = $stmt->fetchAll();

        $totalMinutes = 0;
        $perMilestone = [];
        foreach ($entries as $e) {
            $mins = (int) ($e['duration_minutes'] ?? 0);
            $totalMinutes += $mins;
            $mid = $e['milestone_id'];
            if ($mid !== null) {
                $perMilestone[$mid] = ($perMilestone[$mid] ?? 0) + $mins;
            }
        }

        return $this->json($response, 200, [
            'entries' => $entries,
            'totals' => [
                'minutes' => $totalMinutes,
                'per_milestone' => (object) $perMilestone,
            ],
        ]);
    }
}
