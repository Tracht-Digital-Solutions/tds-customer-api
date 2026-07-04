<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\Ticket;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;

/**
 * POST /admin/tickets/{id}/comments — an admin replies (author_type=owner) or
 * adds an internal note ({ body, isInternal }). A non-internal reply can notify
 * the customer (gated by the ticket_setting toggle).
 */
final class CommentAction extends BaseAction
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketSettings $settings,
        private readonly TicketMailer $mailer,
        private readonly PDO $pdo,
    ) {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $row = $this->tickets->findRow($id);
        if ($row === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $uid = is_array($claims) && isset($claims['uid']) && is_int($claims['uid']) ? $claims['uid'] : null;

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }
        $text = trim((string) ($body['body'] ?? ''));
        if (mb_strlen($text) < 1 || mb_strlen($text) > 10000) {
            return $this->json($response, 422, ['error' => 'body must be 1-10000 chars']);
        }
        $isInternal = (bool) ($body['isInternal'] ?? false);

        $commentId = $this->tickets->addComment([
            'ticket_id' => $id,
            'author_type' => 'owner',
            'author_user_id' => $uid,
            'body' => $text,
            'is_internal' => $isInternal,
        ]);
        $this->tickets->touch($id);

        // Notify the customer only for a public reply (never for internal notes).
        if (!$isInternal && $this->settings->enabled('notify_customer_on_reply')) {
            $email = $this->customerEmail((int) $row['customer_id']);
            if ($email !== null) {
                $this->mailer->notifyCustomerReply($email, $id, (string) $row['subject']);
            }
        }

        return $this->json($response, 201, ['id' => $commentId]);
    }

    private function customerEmail(int $customerId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM customer WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        $email = $stmt->fetchColumn();
        return $email === false ? null : (string) $email;
    }
}
