<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TimeEntry;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\LexwareClient;
use Tds\CustomerApi\Service\LexwareException;
use Tds\CustomerApi\Service\LexwareInvoiceBuilder;

/**
 * POST /admin/time-entries/export-lexware
 *
 * Aggregates a project's billable (completed) time entries into a
 * Lexware Office invoice. Body:
 *   projectId (required), hourlyRate (net €/h; falls back to the
 *   configured default), taxRatePercentage (default 19), from/to
 *   (YYYY-MM-DD, optional), finalize (bool — default false = draft).
 *
 * Returns 201 with the created Lexware invoice id; 503 when the
 * integration isn't configured, 422 when there's nothing to bill,
 * 502 when Lexware rejects the request.
 */
final class ExportLexwareAction extends BaseAction
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LexwareClient $lexware,
        private readonly LexwareInvoiceBuilder $builder,
        private readonly float $defaultHourlyRate,
        private readonly float $defaultTaxRate,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if (!$this->lexware->isConfigured()) {
            return $this->json($response, 503, ['error' => 'Lexware-Anbindung ist nicht konfiguriert (LEXWARE_API_KEY fehlt).']);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $projectId = isset($body['projectId']) && ctype_digit((string) $body['projectId'])
            ? (int) $body['projectId']
            : 0;
        if ($projectId === 0) {
            return $this->json($response, 422, ['error' => 'projectId required']);
        }

        $project = $this->fetchProject($projectId);
        if ($project === null) {
            return $this->json($response, 404, ['error' => 'Projekt nicht gefunden']);
        }

        $hourlyRate = isset($body['hourlyRate']) && is_numeric($body['hourlyRate'])
            ? (float) $body['hourlyRate']
            : $this->defaultHourlyRate;
        if ($hourlyRate <= 0) {
            return $this->json($response, 422, ['error' => 'hourlyRate (Netto €/Std) erforderlich und > 0']);
        }

        $taxRate = isset($body['taxRatePercentage']) && is_numeric($body['taxRatePercentage'])
            ? (float) $body['taxRatePercentage']
            : $this->defaultTaxRate;

        $finalize = ($body['finalize'] ?? false) === true || ($body['finalize'] ?? null) === '1';

        $from = $this->validDate($body['from'] ?? null);
        $to = $this->validDate($body['to'] ?? null);

        $entries = $this->fetchEntries($projectId, $from, $to);

        $built = $this->builder->build(
            entries: $entries,
            customerName: (string) $project['customer_name'],
            projectTitle: (string) $project['title'],
            hourlyRateNet: $hourlyRate,
            taxRatePercentage: $taxRate,
            voucherDate: new \DateTimeImmutable('now'),
        );

        if ($built['lineItemCount'] === 0) {
            return $this->json($response, 422, ['error' => 'Keine abrechenbaren Zeiten im gewählten Zeitraum.']);
        }

        try {
            $invoice = $this->lexware->createInvoice($built['payload'], $finalize);
        } catch (LexwareException $e) {
            $status = $e->httpStatus === 0 ? 503 : 502;
            return $this->json($response, $status, ['error' => $e->getMessage()]);
        }

        return $this->json($response, 201, [
            'lexware' => [
                'id' => $invoice['id'] ?? null,
                'resourceUri' => $invoice['resourceUri'] ?? null,
            ],
            'finalized' => $finalize,
            'totalMinutes' => $built['totalMinutes'],
            'lineItemCount' => $built['lineItemCount'],
        ]);
    }

    /** @return array<string,mixed>|null */
    private function fetchProject(int $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.title, c.name AS customer_name '
            . 'FROM project p INNER JOIN customer c ON c.id = p.customer_id '
            . 'WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $projectId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchEntries(int $projectId, ?string $from, ?string $to): array
    {
        $where = ['te.project_id = :pid', 'te.ended_at IS NOT NULL'];
        $params = ['pid' => $projectId];
        if ($from !== null) {
            $where[] = 'te.started_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = 'te.started_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        $sql = 'SELECT te.duration_minutes, m.title AS milestone_title '
            . 'FROM time_entry te LEFT JOIN milestone m ON m.id = te.milestone_id '
            . 'WHERE ' . implode(' AND ', $where)
            . ' ORDER BY te.started_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function validDate(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
