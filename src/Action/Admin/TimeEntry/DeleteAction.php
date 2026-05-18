<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TimeEntryRepository;

/** DELETE /admin/time-entries/{id} */
final class DeleteAction extends BaseAction
{
    public function __construct(private readonly TimeEntryRepository $repo)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $entry = $this->repo->findById($id);
        if ($entry === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }
        $this->repo->delete($id);
        return $this->json($response, 200, ['id' => $id]);
    }
}
