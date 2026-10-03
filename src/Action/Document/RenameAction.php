<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Document;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * PATCH /documents/{id} — rename the user-visible filename only. The
 * underlying storage_path is left alone; it's keyed by UUID and never
 * shown, so swapping it would just create churn without changing the
 * URL. Customer can only rename documents that belong to them.
 *
 * Body: { filename: string } — same sanitisation rules as UploadAction
 * (a-z 0-9 . _ -) but with one extension dot allowed. Empty / pure
 * whitespace / >255 chars is rejected.
 */
final class RenameAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $documentId = (int) ($args['id'] ?? 0);

        $body = $request->getParsedBody();
        if (!is_array($body) || !isset($body['filename'])) {
            return $this->json($response, 400, ['error' => 'filename required']);
        }

        $raw = trim((string) $body['filename']);
        if ($raw === '' || strlen($raw) > 255) {
            return $this->json($response, 422, ['error' => 'filename must be 1–255 chars']);
        }

        $clean = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $raw);
        if ($clean === '' || $clean === '.' || $clean === '..') {
            return $this->json($response, 422, ['error' => 'filename contains no valid characters']);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE document SET filename = :fn WHERE id = :id AND customer_id = :cid'
        );
        $stmt->execute([
            'fn' => $clean,
            'id' => $documentId,
            'cid' => $customerId,
        ]);

        // rowCount() is 0 both when the document doesn't exist/isn't ours AND
        // when the sanitised name already matches what's stored — this PDO runs
        // without MYSQL_ATTR_FOUND_ROWS, so MySQL reports *changed* rows, not
        // matched, and the UPDATE bumps no timestamp. Distinguish a real miss
        // from a no-op with an ownership probe so a rename-to-same-name (e.g.
        // "my report.pdf" collapsing to the stored "my_report.pdf") isn't a 404.
        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT 1 FROM document WHERE id = :id AND customer_id = :cid LIMIT 1'
            );
            $check->execute(['id' => $documentId, 'cid' => $customerId]);
            if ($check->fetchColumn() === false) {
                return $this->json($response, 404, ['error' => 'Not found']);
            }
        }

        return $this->json($response, 200, ['id' => $documentId, 'filename' => $clean]);
    }
}
