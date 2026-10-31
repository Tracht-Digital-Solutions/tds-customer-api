<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TimeEntryRepository;

/**
 * GET /admin/time-entries/timer — current running timer (or null).
 * Joins project/milestone titles for direct display in the admin UI.
 */
final class TimerCurrentAction extends BaseAction
{
    public function __construct(
        private readonly TimeEntryRepository $repo,
        private readonly PDO $pdo,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $running = $this->repo->runningEntry();
        if ($running === null) {
            return $this->json($response, 200, ['running' => null]);
        }

        $stmt = $this->pdo->prepare(
            'SELECT te.*, p.title AS project_title, p.customer_id, m.title AS milestone_title '
            . 'FROM time_entry te '
            . 'INNER JOIN project p ON p.id = te.project_id '
            . 'LEFT JOIN milestone m ON m.id = te.milestone_id '
            . 'WHERE te.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $running['id']]);
        $row = $stmt->fetch();
        return $this->json($response, 200, ['running' => $row === false ? null : $row]);
    }
}
