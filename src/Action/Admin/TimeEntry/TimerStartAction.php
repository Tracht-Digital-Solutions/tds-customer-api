<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TimeEntryRepository;

/**
 * POST /admin/time-entries/timer/start — opens a row with
 * ended_at=NULL on the given project. Returns 409 with the open
 * entry if a timer is already running, so the client can either stop
 * it or surface a "you already have a timer running" hint.
 */
final class TimerStartAction extends BaseAction
{
    public function __construct(private readonly TimeEntryRepository $repo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $running = $this->repo->runningEntry();
        if ($running !== null) {
            return $this->json($response, 409, [
                'error' => 'A timer is already running',
                'running' => $running,
            ]);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $projectId = isset($body['project_id']) && ctype_digit((string) $body['project_id'])
            ? (int) $body['project_id']
            : 0;
        if ($projectId === 0 || !$this->repo->projectExists($projectId)) {
            return $this->json($response, 422, ['error' => 'Valid project_id required']);
        }

        $milestoneId = null;
        if (isset($body['milestone_id']) && $body['milestone_id'] !== null && $body['milestone_id'] !== '') {
            if (!ctype_digit((string) $body['milestone_id'])) {
                return $this->json($response, 422, ['error' => 'milestone_id must be an integer']);
            }
            $milestoneId = (int) $body['milestone_id'];
            if (!$this->repo->milestoneBelongsToProject($milestoneId, $projectId)) {
                return $this->json($response, 422, ['error' => 'milestone does not belong to the given project']);
            }
        }

        $description = isset($body['description']) ? trim((string) $body['description']) : '';
        if (strlen($description) > 5000) {
            return $this->json($response, 422, ['error' => 'description must be at most 5000 chars']);
        }

        $id = $this->repo->startTimer($projectId, $milestoneId, $description);
        return $this->json($response, 201, ['id' => $id]);
    }
}
