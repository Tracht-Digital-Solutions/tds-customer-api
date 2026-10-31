<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Message;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/** GET /messages?projectId= — message thread for the customer (optionally filtered by project). */
final class ListAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $projectId = $request->getQueryParams()['projectId'] ?? null;

        $sql = "SELECT id, customer_id, project_id, author_type, body, created_at, read_at, edited_at "
             . "FROM message WHERE customer_id = :cid";
        $params = ['cid' => $customerId];

        if ($projectId !== null && ctype_digit((string) $projectId)) {
            $sql .= " AND project_id = :pid";
            $params['pid'] = (int) $projectId;
        }
        $sql .= " ORDER BY created_at ASC, id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->json($response, 200, ['messages' => $stmt->fetchAll()]);
    }
}
