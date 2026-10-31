<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Invoice;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/** GET /invoices — list all invoices for the authenticated customer. */
final class ListAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);

        $stmt = $this->pdo->prepare(
            "SELECT id, customer_id, project_id, amount_cents, currency, status, "
            . "due_date, paid_at, created_at "
            . "FROM invoice WHERE customer_id = :cid ORDER BY due_date DESC, id DESC"
        );
        $stmt->execute(['cid' => $customerId]);

        return $this->json($response, 200, ['invoices' => $stmt->fetchAll()]);
    }
}
