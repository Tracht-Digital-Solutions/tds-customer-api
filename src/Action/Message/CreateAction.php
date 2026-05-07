<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Message;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

/** POST /messages — body: { body, projectId? }. author_type derived from JWT. */
final class CreateAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $isAdmin = is_array($claims) ? (bool) ($claims['admin'] ?? false) : false;

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $text = trim((string) ($body['body'] ?? ''));
        if (strlen($text) < 1 || strlen($text) > 10000) {
            return $this->json($response, 422, ['error' => 'body must be 1-10000 chars']);
        }

        $projectId = isset($body['projectId']) && ctype_digit((string) $body['projectId'])
            ? (int) $body['projectId']
            : null;

        $stmt = $this->pdo->prepare(
            "INSERT INTO message (customer_id, project_id, author_type, body, created_at) "
            . "VALUES (:cid, :pid, :at, :body, NOW())"
        );
        $stmt->execute([
            'cid' => $customerId,
            'pid' => $projectId,
            'at' => $isAdmin ? 'owner' : 'customer',
            'body' => $text,
        ]);

        return $this->json($response, 201, [
            'id' => (int) $this->pdo->lastInsertId(),
        ]);
    }
}
