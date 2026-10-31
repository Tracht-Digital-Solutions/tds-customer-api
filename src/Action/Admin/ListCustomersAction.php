<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * GET /admin/customers
 *
 * Lists companies (customer profiles) so the admin user-management UI can
 * group accounts by company and offer a company picker. Gated by the
 * admin-JWT JwksAuthMiddleware(requireAdmin: true).
 */
final class ListCustomersAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $rows = $this->pdo
            ->query('SELECT id, email, name, created_at FROM customer ORDER BY name ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        $customers = array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'name' => (string) $r['name'],
            'createdAt' => (string) $r['created_at'],
        ], $rows);

        return $this->json($response, 200, ['customers' => $customers]);
    }
}
