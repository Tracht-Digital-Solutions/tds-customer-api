<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Domain\Ticket;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;
use Tds\CustomerApi\Service\TicketStatusRepository;

/**
 * PATCH /admin/tickets/{id} — triage a ticket. Partial body:
 * { statusId?, priority?, type?, assigneeUserId?, projectId?,
 *   customerActionRequired?, customerActionNote? }.
 *
 * Moving to a terminal status stamps closed_at (and clears it when reopening).
 * A visible status change or a new reply can notify the customer (gated by the
 * ticket_setting toggles + a configured mailer).
 */
final class UpdateAction extends BaseAction
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketStatusRepository $statuses,
        private readonly TicketSettings $settings,
        private readonly TicketMailer $mailer,
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $fields = [];
        $statusChanged = false;
        $newStatus = null;

        if (array_key_exists('statusId', $body)) {
            $statusId = (int) $body['statusId'];
            $newStatus = $this->statuses->find($statusId);
            if ($newStatus === null) {
                return $this->json($response, 422, ['error' => 'unknown statusId']);
            }
            $fields['status_id'] = $statusId;
            $statusChanged = $statusId !== (int) $row['status_id'];
            // Terminal status closes the ticket; reopening clears closed_at.
            if ($newStatus['isTerminal']) {
                $fields['closed_at'] = date('Y-m-d H:i:s');
            } else {
                $fields['closed_at'] = null;
            }
        }

        if (array_key_exists('priority', $body)) {
            $priority = (string) $body['priority'];
            if (!Ticket::isValidPriority($priority)) {
                return $this->json($response, 422, ['error' => 'invalid priority']);
            }
            $fields['priority'] = $priority;
        }

        if (array_key_exists('type', $body)) {
            $type = (string) $body['type'];
            if (!Ticket::isValidType($type)) {
                return $this->json($response, 422, ['error' => 'invalid type']);
            }
            $fields['type'] = $type;
        }

        if (array_key_exists('assigneeUserId', $body)) {
            $raw = $body['assigneeUserId'];
            if ($raw === null || $raw === '' || (int) $raw <= 0) {
                $fields['assignee_user_id'] = null;
            } else {
                $fields['assignee_user_id'] = (int) $raw;
            }
        }

        if (array_key_exists('projectId', $body)) {
            $raw = $body['projectId'];
            $fields['project_id'] = ($raw === null || $raw === '' || (int) $raw <= 0) ? null : (int) $raw;
        }

        if (array_key_exists('customerActionRequired', $body)) {
            $fields['customer_action_required'] = (bool) $body['customerActionRequired'] ? 1 : 0;
        }

        if (array_key_exists('customerActionNote', $body)) {
            $note = $body['customerActionNote'];
            if ($note === null || trim((string) $note) === '') {
                $fields['customer_action_note'] = null;
            } else {
                if (mb_strlen((string) $note) > 2000) {
                    return $this->json($response, 422, ['error' => 'customerActionNote too long']);
                }
                $fields['customer_action_note'] = trim((string) $note);
            }
        }

        if ($fields === []) {
            return $this->json($response, 200, ['ticket' => $this->tickets->present($row, forCustomer: false)]);
        }

        $this->tickets->update($id, $fields);

        // Best-effort customer notification on a visible status change.
        if ($statusChanged
            && $newStatus !== null
            && $newStatus['visibleToCustomer']
            && $this->settings->enabled('notify_customer_on_status')
        ) {
            $email = $this->tickets->notifyEmail($row);
            if ($email !== null) {
                $this->mailer->notifyCustomerStatusChange($email, $id, (string) $row['subject'], (string) $newStatus['name']);
            }
        }

        $updated = $this->tickets->findRow($id);
        return $this->json($response, 200, ['ticket' => $this->tickets->present($updated ?? $row, forCustomer: false)]);
    }
}
