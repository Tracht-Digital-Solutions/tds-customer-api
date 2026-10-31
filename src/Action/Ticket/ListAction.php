<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketRepository;

/** GET /tickets — the customer's own tickets (statuses resolved for the customer). */
final class ListAction extends BaseAction
{
    public function __construct(private readonly TicketRepository $tickets)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);
        return $this->json($response, 200, ['tickets' => $this->tickets->customerList($customerId)]);
    }
}
