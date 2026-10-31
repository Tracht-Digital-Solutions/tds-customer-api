<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TicketStatus;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketStatusRepository;

/**
 * DELETE /admin/ticket-statuses/{id} — remove a status. Refused (409) when the
 * status is still assigned to any ticket (the FK is RESTRICT) or when it is the
 * last remaining status (a ticket must always have a valid status to move to).
 */
final class DeleteAction extends BaseAction
{
    public function __construct(private readonly TicketStatusRepository $statuses)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        if ($this->statuses->find($id) === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }
        if ($this->statuses->isInUse($id)) {
            return $this->json($response, 409, ['error' => 'Status is in use by one or more tickets']);
        }
        if ($this->statuses->count() <= 1) {
            return $this->json($response, 409, ['error' => 'Cannot delete the last status']);
        }

        $this->statuses->delete($id);
        return $response->withStatus(204);
    }
}
