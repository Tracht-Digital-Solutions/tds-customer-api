<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TicketStatus;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketStatusRepository;

/** PATCH /admin/ticket-statuses/{id} — edit a status (full replace of its fields). */
final class UpdateAction extends BaseAction
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $data = StatusPayload::parse($body);
        if (is_string($data)) {
            return $this->json($response, 422, ['error' => $data]);
        }

        $this->statuses->update($id, $data);
        return $this->json($response, 200, ['status' => $this->statuses->find($id)]);
    }
}
