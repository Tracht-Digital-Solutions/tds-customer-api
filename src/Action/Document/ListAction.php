<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Document;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/** GET /documents — list all documents for the authenticated customer. */
final class ListAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $projectId = $request->getQueryParams()['projectId'] ?? null;

        $sql = "SELECT id, customer_id, project_id, filename, mime_type, size_bytes, uploaded_at "
             . "FROM document WHERE customer_id = :cid";
        $params = ['cid' => $customerId];

        if ($projectId !== null && ctype_digit((string) $projectId)) {
            $sql .= " AND project_id = :pid";
            $params['pid'] = (int) $projectId;
        }
        $sql .= " ORDER BY uploaded_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->json($response, 200, ['documents' => $stmt->fetchAll()]);
    }
}
