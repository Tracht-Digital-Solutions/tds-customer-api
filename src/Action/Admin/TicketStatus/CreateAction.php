<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TicketStatus;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketStatusRepository;

/**
 * POST /admin/ticket-statuses — add a status.
 * Body: { name, color?, sortOrder?, visibleToCustomer?, isTerminal?, isDefault? }.
 */
final class CreateAction extends BaseAction
{
    public function __construct(private readonly TicketStatusRepository $statuses)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $data = StatusPayload::parse($body);
        if (is_string($data)) {
            return $this->json($response, 422, ['error' => $data]);
        }

        $id = $this->statuses->create($data);
        $created = $this->statuses->find($id);
        return $this->json($response, 201, ['status' => $created]);
    }
}
