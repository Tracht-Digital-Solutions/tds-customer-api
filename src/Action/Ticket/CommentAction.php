<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Service\TicketRepository;

/**
 * POST /tickets/{id}/comments — the customer replies on their ticket.
 * A customer reply clears the admin's "customer action required" prompt.
 * Customers cannot post internal notes (that flag is ignored here).
 */
final class CommentAction extends BaseAction
{
    public function __construct(private readonly TicketRepository $tickets)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $uid = is_array($claims) && isset($claims['uid']) && is_int($claims['uid']) ? $claims['uid'] : null;
        $id = (int) ($args['id'] ?? 0);

        $row = $this->tickets->findRow($id, $customerId);
        if ($row === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }
        $text = trim((string) ($body['body'] ?? ''));
        if (mb_strlen($text) < 1 || mb_strlen($text) > 10000) {
            return $this->json($response, 422, ['error' => 'body must be 1-10000 chars']);
        }

        $commentId = $this->tickets->addComment([
            'ticket_id' => $id,
            'author_type' => 'customer',
            'author_user_id' => $uid,
            'body' => $text,
            'is_internal' => false,
        ]);
        // A customer reply resolves any pending "action required" prompt and
        // bumps the ticket so it resurfaces for the admin.
        $this->tickets->clearCustomerAction($id);

        return $this->json($response, 201, ['id' => $commentId]);
    }
}
