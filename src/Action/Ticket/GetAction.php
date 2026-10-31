<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketRepository;

/**
 * GET /tickets/{id} — ticket detail for the customer: the ticket, its
 * non-internal comments, and its attachments. Scoped to the customer so ids
 * aren't enumerable (a foreign id 404s).
 */
final class GetAction extends BaseAction
{
    public function __construct(private readonly TicketRepository $tickets)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $id = (int) ($args['id'] ?? 0);

        $row = $this->tickets->findRow($id, $customerId);
        if ($row === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        return $this->json($response, 200, [
            'ticket' => $this->tickets->present($row, forCustomer: true),
            'comments' => $this->tickets->comments($id, includeInternal: false),
            'attachments' => $this->tickets->attachments($id),
        ]);
    }
}
