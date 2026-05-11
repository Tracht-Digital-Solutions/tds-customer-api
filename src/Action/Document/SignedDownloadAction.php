<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Document;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Service\DocumentSigner;

/**
 * GET /documents/sign?d=&c=&exp=&sig=
 *
 * Streams the document if the HMAC validates and the URL hasn't
 * expired. NOT behind JwksAuthMiddleware — the signature IS the
 * auth, which is the whole point of signed URLs.
 *
 * Ownership is re-verified at download time against the customer_id
 * in the URL — even if the signing customer's DB row got pulled
 * between issuance and use, we don't serve files to someone who
 * doesn't own them.
 */
final class SignedDownloadAction
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DocumentSigner $signer,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $documentId = (int) ($params['d'] ?? 0);
        $customerId = (int) ($params['c'] ?? 0);
        $exp = (int) ($params['exp'] ?? 0);
        $sig = (string) ($params['sig'] ?? '');

        if ($documentId <= 0 || $customerId <= 0 || $exp <= 0 || $sig === '') {
            return $this->error($response, 400, 'Missing or invalid signature parameters');
        }

        if (!$this->signer->verify($documentId, $customerId, $exp, $sig)) {
            return $this->error($response, 403, 'Signature invalid or expired');
        }

        $stmt = $this->pdo->prepare(
            'SELECT filename, storage_path, mime_type, size_bytes '
            . 'FROM document WHERE id = :id AND customer_id = :cid LIMIT 1',
        );
        $stmt->execute(['id' => $documentId, 'cid' => $customerId]);
        $doc = $stmt->fetch();
        if ($doc === false) {
            return $this->error($response, 404, 'Not found');
        }

        $rootDir = (string) (getenv('DOCUMENT_ROOT_DIR') ?: '');
        $absPath = $rootDir . DIRECTORY_SEPARATOR . $doc['storage_path'];
        if (!is_readable($absPath)) {
            return $this->error($response, 410, 'File no longer present on disk');
        }

        $stream = (new StreamFactory())->createStreamFromFile($absPath, 'rb');
        return $response
            ->withHeader('Content-Type', (string) $doc['mime_type'])
            ->withHeader('Content-Length', (string) $doc['size_bytes'])
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="%s"',
                addslashes((string) $doc['filename']),
            ))
            ->withHeader('Cache-Control', 'private, max-age=0, no-store')
            ->withBody($stream);
    }

    private function error(Response $response, int $status, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
