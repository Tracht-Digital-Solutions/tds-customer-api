<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Domain\Ticket;
use Tds\CustomerApi\Service\TicketRepository;

/**
 * GET /admin/tickets — all tickets with the real (unmasked) status + customer
 * display info. Optional filters: statusId, assigneeUserId, priority, type,
 * customerId, q (subject/description search). Not customer-scoped (admin JWT
 * gate). The `type` filter separates contact tickets (type='contact') from
 * support tickets in the admin UI.
 */
final class ListAction extends BaseAction
{
    public function __construct(private readonly TicketRepository $tickets)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $q = $request->getQueryParams();
        $filters = [];

        if (isset($q['statusId']) && ctype_digit((string) $q['statusId'])) {
            $filters['status_id'] = (int) $q['statusId'];
        }
        if (isset($q['assigneeUserId']) && ctype_digit((string) $q['assigneeUserId'])) {
            $filters['assignee_user_id'] = (int) $q['assigneeUserId'];
        }
        if (isset($q['priority']) && Ticket::isValidPriority((string) $q['priority'])) {
            $filters['priority'] = (string) $q['priority'];
        }
        if (isset($q['type']) && Ticket::isValidType((string) $q['type'])) {
            $filters['type'] = (string) $q['type'];
        }
        if (isset($q['customerId']) && ctype_digit((string) $q['customerId'])) {
            $filters['customer_id'] = (int) $q['customerId'];
        }
        if (isset($q['q']) && trim((string) $q['q']) !== '') {
            $filters['q'] = trim((string) $q['q']);
        }

        return $this->json($response, 200, ['tickets' => $this->tickets->adminList($filters)]);
    }
}
