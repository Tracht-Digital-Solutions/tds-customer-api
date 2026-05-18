<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TimeEntryRepository;

/**
 * POST /admin/time-entries — manual entry. Accepts either
 * (started_at + ended_at) or (started_at + duration_minutes). The
 * missing dimension is derived server-side so the stored row always
 * has all three populated, which keeps reporting queries simple.
 */
final class CreateAction extends BaseAction
{
    public function __construct(private readonly TimeEntryRepository $repo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
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

        $startedAt = self::parseDateTime($body['started_at'] ?? null);
        if ($startedAt === null) {
            return $this->json($response, 422, ['error' => 'started_at required (YYYY-MM-DD HH:MM:SS or ISO 8601)']);
        }

        $endedAt = self::parseDateTime($body['ended_at'] ?? null);
        $duration = isset($body['duration_minutes']) && is_numeric($body['duration_minutes'])
            ? (int) $body['duration_minutes']
            : null;

        if ($endedAt === null && $duration === null) {
            return $this->json($response, 422, ['error' => 'either ended_at or duration_minutes is required']);
        }
        if ($endedAt === null) {
            $endedAt = (clone $startedAt)->modify("+{$duration} minutes");
        } elseif ($duration === null) {
            $duration = max(0, (int) round(($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60));
        }
        if ($endedAt < $startedAt) {
            return $this->json($response, 422, ['error' => 'ended_at must be after started_at']);
        }
        if ($duration < 0 || $duration > 24 * 60) {
            return $this->json($response, 422, ['error' => 'duration must be 0–1440 minutes']);
        }

        $description = isset($body['description']) ? trim((string) $body['description']) : '';
        if (strlen($description) > 5000) {
            return $this->json($response, 422, ['error' => 'description must be at most 5000 chars']);
        }

        $id = $this->repo->insertManual(
            $projectId,
            $milestoneId,
            $startedAt->format('Y-m-d H:i:s'),
            $endedAt->format('Y-m-d H:i:s'),
            $duration,
            $description,
        );

        return $this->json($response, 201, ['id' => $id]);
    }

    private static function parseDateTime(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || $v === '') return null;
        try {
            return new \DateTimeImmutable($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
