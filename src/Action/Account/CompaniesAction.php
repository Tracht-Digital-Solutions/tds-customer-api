<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Account;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Support\ActiveCompany;

/**
 * GET /me/companies
 *
 * The companies the current login can access, with their display names, for the
 * portal's company switcher. auth-api's JWT carries the company ids + per-company
 * permissions but not the names (those live here), so the frontend zips this
 * against the auth `/me` `companies` claim. Returns `[]` for an admin principal
 * (admins use `/admin/customers`).
 */
final class CompaniesAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $claims = is_array($claims) ? $claims : [];

        $ids = ActiveCompany::allowedIds($claims);
        if ($ids === []) {
            return $this->json($response, 200, ['companies' => []]);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM customer WHERE id IN ($placeholders) ORDER BY name ASC"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $companies = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $rows);

        return $this->json($response, 200, ['companies' => $companies]);
    }
}
