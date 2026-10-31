<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TicketStatus;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketStatusRepository;

/** GET /admin/ticket-statuses — the full configurable status registry. */
final class ListAction extends BaseAction
{
    public function __construct(private readonly TicketStatusRepository $statuses)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        return $this->json($response, 200, ['statuses' => $this->statuses->all()]);
    }
}
