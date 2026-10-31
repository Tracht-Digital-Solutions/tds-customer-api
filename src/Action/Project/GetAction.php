<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Project;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/** GET /projects/{id} — project detail with milestones. */
final class GetAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $projectId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM project WHERE id = :id AND customer_id = :cid LIMIT 1"
        );
        $stmt->execute(['id' => $projectId, 'cid' => $customerId]);
        $project = $stmt->fetch();
        if ($project === false) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $ms = $this->pdo->prepare(
            "SELECT id, project_id, title, status, due_date, completed_at, sort_order "
            . "FROM milestone WHERE project_id = :pid ORDER BY sort_order ASC, id ASC"
        );
        $ms->execute(['pid' => $projectId]);

        return $this->json($response, 200, [
            'project' => $project,
            'milestones' => $ms->fetchAll(),
        ]);
    }
}
