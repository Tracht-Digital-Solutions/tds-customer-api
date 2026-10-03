<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Account;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * GET /me
 *
 * Returns the authed customer's profile (id, email, name, created_at).
 * Backs the account settings page.
 */
final class GetMeAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);

        $stmt = $this->pdo->prepare(
            'SELECT id, email, name, created_at FROM customer WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        return $this->json($response, 200, ['customer' => $row]);
    }
}
