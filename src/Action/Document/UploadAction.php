<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Document;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * POST /documents — multipart upload.
 *
 * Stores under \$DOCUMENT_ROOT_DIR/{customer_id}/{uuid}-{filename}.
 * Issue #4 hardens this with mime allowlist + size cap.
 */
final class UploadAction extends BaseAction
{
    private const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, 400, ['error' => 'No valid file uploaded under "file"']);
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_BYTES) {
            return $this->json($response, 413, ['error' => 'File exceeds 25 MB limit']);
        }

        $mime = $file->getClientMediaType() ?? 'application/octet-stream';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return $this->json($response, 415, ['error' => 'Mime type not allowed', 'mime' => $mime]);
        }

        $rootDir = (string) (getenv('DOCUMENT_ROOT_DIR') ?: '');
        if ($rootDir === '' || !is_dir($rootDir) || !is_writable($rootDir)) {
            return $this->json($response, 503, ['error' => 'Document storage unavailable']);
        }

        $customerDir = $rootDir . DIRECTORY_SEPARATOR . $customerId;
        if (!is_dir($customerDir)) {
            mkdir($customerDir, 0700, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) $file->getClientFilename());
        $uuid = bin2hex(random_bytes(8));
        $relPath = $customerId . '/' . $uuid . '-' . $safeName;
        $absPath = $rootDir . DIRECTORY_SEPARATOR . $relPath;

        $file->moveTo($absPath);

        $body = $request->getParsedBody();
        $projectId = is_array($body) && isset($body['projectId']) && ctype_digit((string) $body['projectId'])
            ? (int) $body['projectId']
            : null;

        $stmt = $this->pdo->prepare(
            "INSERT INTO document (customer_id, project_id, filename, storage_path, mime_type, size_bytes, uploaded_at) "
            . "VALUES (:cid, :pid, :fn, :sp, :mt, :sb, NOW())"
        );
        $stmt->execute([
            'cid' => $customerId,
            'pid' => $projectId,
            'fn' => $safeName,
            'sp' => $relPath,
            'mt' => $mime,
            'sb' => $file->getSize(),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        return $this->json($response, 201, ['id' => $id, 'filename' => $safeName]);
    }
}
