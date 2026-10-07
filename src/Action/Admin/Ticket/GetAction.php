<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketRepository;

/**
 * GET /admin/tickets/{id} — full ticket detail for an admin: real status, ALL
 * comments (including internal notes) and attachments. Any customer's ticket.
 */
final class GetAction extends BaseAction
{
    public function __construct(private readonly TicketRepository $tickets)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $row = $this->tickets->findRow($id);
        if ($row === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        return $this->json($response, 200, [
            'ticket' => $this->tickets->present($row, forCustomer: false),
            'comments' => $this->tickets->comments($id, includeInternal: true),
            'attachments' => $this->tickets->attachments($id),
        ]);
    }
}
