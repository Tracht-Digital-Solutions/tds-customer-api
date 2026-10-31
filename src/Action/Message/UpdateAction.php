<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Message;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

/**
 * PATCH /messages/{id} — edit the body of a message in place.
 *
 * Ownership rules:
 * - Admin (JWT `admin=true`) can edit any message.
 * - Customer can only edit their own `author_type='customer'`
 *   messages. The WHERE clause enforces both — a non-matching
 *   rowCount returns 404 (indistinguishable from "doesn't exist"
 *   so message IDs aren't leakable).
 *
 * On a successful update, `edited_at` is set to NOW() so the
 * frontend can render a "(bearbeitet)" indicator.
 */
final class UpdateAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $isAdmin = is_array($claims) ? (bool) ($claims['admin'] ?? false) : false;

        $messageId = (int) ($args['id'] ?? 0);

        $body = $request->getParsedBody();
        if (!is_array($body) || !isset($body['body'])) {
            return $this->json($response, 400, ['error' => 'body required']);
        }

        $text = trim((string) $body['body']);
        if (strlen($text) < 1 || strlen($text) > 10000) {
            return $this->json($response, 422, ['error' => 'body must be 1-10000 chars']);
        }

        if ($isAdmin) {
            $stmt = $this->pdo->prepare(
                'UPDATE message SET body = :body, edited_at = NOW() WHERE id = :id'
            );
            $stmt->execute(['body' => $text, 'id' => $messageId]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE message SET body = :body, edited_at = NOW() "
                . "WHERE id = :id AND customer_id = :cid AND author_type = 'customer'"
            );
            $stmt->execute(['body' => $text, 'id' => $messageId, 'cid' => $customerId]);
        }

        if ($stmt->rowCount() === 0) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        return $this->json($response, 200, ['id' => $messageId]);
    }
}
