<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TimeEntryRepository;

/**
 * PATCH /admin/time-entries/{id} — full replace of editable fields
 * (milestone_id, started_at, ended_at, duration_minutes, description).
 * Cannot change project_id; delete + recreate if a row needs to move
 * between projects.
 */
final class UpdateAction extends BaseAction
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
        if ($entry['ended_at'] === null) {
            return $this->json($response, 409, ['error' => 'Stop the running timer before editing it']);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $projectId = (int) $entry['project_id'];

        $milestoneId = null;
        if (isset($body['milestone_id']) && $body['milestone_id'] !== null && $body['milestone_id'] !== '') {
            if (!ctype_digit((string) $body['milestone_id'])) {
                return $this->json($response, 422, ['error' => 'milestone_id must be an integer']);
            }
            $milestoneId = (int) $body['milestone_id'];
            if (!$this->repo->milestoneBelongsToProject($milestoneId, $projectId)) {
                return $this->json($response, 422, ['error' => 'milestone does not belong to this entry\'s project']);
            }
        }

        $startedAt = self::parseDateTime($body['started_at'] ?? $entry['started_at']);
        $endedAt = self::parseDateTime($body['ended_at'] ?? $entry['ended_at']);
        if ($startedAt === null || $endedAt === null) {
            return $this->json($response, 422, ['error' => 'started_at and ended_at must be valid datetimes']);
        }
        if ($endedAt < $startedAt) {
            return $this->json($response, 422, ['error' => 'ended_at must be after started_at']);
        }

        $duration = isset($body['duration_minutes']) && is_numeric($body['duration_minutes'])
            ? (int) $body['duration_minutes']
            : (int) round(($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60);
        if ($duration < 0 || $duration > 24 * 60) {
            return $this->json($response, 422, ['error' => 'duration must be 0–1440 minutes']);
        }

        $description = isset($body['description']) ? trim((string) $body['description']) : (string) $entry['description'];
        if (strlen($description) > 5000) {
            return $this->json($response, 422, ['error' => 'description must be at most 5000 chars']);
        }

        $this->repo->update(
            $id,
            $milestoneId,
            $startedAt->format('Y-m-d H:i:s'),
            $endedAt->format('Y-m-d H:i:s'),
            $duration,
            $description,
        );

        return $this->json($response, 200, ['id' => $id]);
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
