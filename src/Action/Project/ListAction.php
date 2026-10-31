<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Project;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/** GET /projects — list all projects for the authenticated customer. */
final class ListAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);

        $stmt = $this->pdo->prepare(
            "SELECT id, customer_id, title, status, start_date, target_date, description "
            . "FROM project WHERE customer_id = :cid ORDER BY id DESC"
        );
        $stmt->execute(['cid' => $customerId]);

        return $this->json($response, 200, [
            'projects' => $stmt->fetchAll(),
        ]);
    }
}
