<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TimeEntryRepository;

/**
 * POST /admin/time-entries/timer/stop — finalises the running entry.
 * Description in the body (optional) overwrites whatever the user
 * typed at start. Duration is computed server-side from started_at
 * → NOW() so client clock skew can't poison the report.
 */
final class TimerStopAction extends BaseAction
{
    public function __construct(private readonly TimeEntryRepository $repo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $running = $this->repo->runningEntry();
        if ($running === null) {
            return $this->json($response, 404, ['error' => 'No timer running']);
        }

        $body = $request->getParsedBody();
        $description = null;
        if (is_array($body) && isset($body['description'])) {
            $description = trim((string) $body['description']);
            if (strlen($description) > 5000) {
                return $this->json($response, 422, ['error' => 'description must be at most 5000 chars']);
            }
        }

        $now = new \DateTimeImmutable();
        $started = new \DateTimeImmutable((string) $running['started_at']);
        $duration = max(0, (int) round(($now->getTimestamp() - $started->getTimestamp()) / 60));

        $this->repo->stopTimer(
            (int) $running['id'],
            $now->format('Y-m-d H:i:s'),
            $duration,
            $description,
        );

        return $this->json($response, 200, [
            'id' => (int) $running['id'],
            'duration_minutes' => $duration,
            'ended_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
