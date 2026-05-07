<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Document;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * GET /documents/{id}/download
 *
 * Streams the file. Customer ownership verified via JWT.
 *
 * Issue #5 layers on signed-URL TTL so URLs can be shared with
 * frontend without re-auth (e.g., for img tags). Until then, the
 * frontend fetches with its JWT and renders blob URLs.
 */
final class DownloadAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $documentId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT filename, storage_path, mime_type, size_bytes "
            . "FROM document WHERE id = :id AND customer_id = :cid LIMIT 1"
        );
        $stmt->execute(['id' => $documentId, 'cid' => $customerId]);
        $doc = $stmt->fetch();

        if ($doc === false) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $rootDir = (string) (getenv('DOCUMENT_ROOT_DIR') ?: '');
        $absPath = $rootDir . DIRECTORY_SEPARATOR . $doc['storage_path'];
        if (!is_readable($absPath)) {
            return $this->json($response, 410, ['error' => 'File no longer present on disk']);
        }

        $stream = (new StreamFactory())->createStreamFromFile($absPath, 'rb');
        return $response
            ->withHeader('Content-Type', (string) $doc['mime_type'])
            ->withHeader('Content-Length', (string) $doc['size_bytes'])
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="%s"',
                addslashes((string) $doc['filename'])
            ))
            ->withBody($stream);
    }
}
