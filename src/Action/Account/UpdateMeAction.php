<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Account;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * PATCH /me
 *
 * Customer self-service profile update. Today only the `name` field
 * is editable in place — changing `email` would have to atomically
 * update `customer_credential.email` in tds-auth-api too, which is a
 * cross-service transaction we don't have. For now, email change is
 * a support-channel ask (see Account.tsx copy).
 *
 * Body: { name?: string }   // 1-200 chars, trimmed
 *
 * Idempotent: omitting `name` (or sending an empty body) just returns
 * the current profile.
 */
final class UpdateMeAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body.']);
        }

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '' || strlen($name) > 200) {
                return $this->json($response, 422, [
                    'error' => 'Invalid input.',
                    'issues' => ['name' => ['must be 1-200 chars']],
                ]);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE customer SET name = :name WHERE id = :id'
            );
            $stmt->execute(['name' => $name, 'id' => $customerId]);
        }

        // Always return the (possibly unchanged) row — saves the
        // frontend an extra GET round-trip and avoids the editor
        // showing stale data after a quiet save.
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
