<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Domain\Ticket;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;
use Tds\CustomerApi\Service\TicketStatusRepository;

/**
 * POST /tickets — a customer opens a support request.
 * Body: { subject, description, priority?, type?, projectId? }.
 *
 * Validation is a hand-duplicate of TicketCreateSchema in tds-shared.
 */
final class CreateAction extends BaseAction
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketStatusRepository $statuses,
        private readonly TicketSettings $settings,
        private readonly TicketMailer $mailer,
        private readonly \PDO $pdo,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $isAdmin = is_array($claims) ? (bool) ($claims['admin'] ?? false) : false;
        $uid = is_array($claims) && isset($claims['uid']) && is_int($claims['uid']) ? $claims['uid'] : null;

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $subject = trim((string) ($body['subject'] ?? ''));
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200) {
            return $this->json($response, 422, ['error' => 'subject must be 3-200 chars']);
        }
        $description = trim((string) ($body['description'] ?? ''));
        if (mb_strlen($description) < 10 || mb_strlen($description) > 10000) {
            return $this->json($response, 422, ['error' => 'description must be 10-10000 chars']);
        }

        $priority = (string) ($body['priority'] ?? Ticket::DEFAULT_PRIORITY);
        if (!Ticket::isValidPriority($priority)) {
            return $this->json($response, 422, ['error' => 'invalid priority']);
        }
        $type = (string) ($body['type'] ?? Ticket::DEFAULT_TYPE);
        if (!Ticket::isValidType($type)) {
            return $this->json($response, 422, ['error' => 'invalid type']);
        }

        $projectId = isset($body['projectId']) && ctype_digit((string) $body['projectId'])
            ? (int) $body['projectId']
            : null;

        $statusId = $this->statuses->defaultId();
        if ($statusId === null) {
            return $this->json($response, 503, ['error' => 'No ticket status configured']);
        }

        $id = $this->tickets->create([
            'customer_id' => $customerId,
            'project_id' => $projectId,
            'status_id' => $statusId,
            'subject' => $subject,
            'description' => $description,
            'priority' => $priority,
            'type' => $type,
            'created_by_type' => $isAdmin ? 'owner' : 'customer',
            'created_by_user_id' => $uid,
        ]);

        // Best-effort admin notification when enabled.
        if ($this->settings->enabled('notify_admin_on_new')) {
            $this->mailer->notifyNewTicket($id, $subject, $this->customerName($customerId));
        }

        return $this->json($response, 201, ['id' => $id]);
    }

    private function customerName(int $customerId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM customer WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        $name = $stmt->fetchColumn();
        return $name === false ? 'Kunde' : (string) $name;
    }
}
