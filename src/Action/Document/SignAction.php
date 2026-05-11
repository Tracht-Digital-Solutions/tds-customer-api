<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Document;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\DocumentSigner;

/**
 * POST /documents/{id}/sign
 *
 * Returns a short-lived signed URL the frontend can stick in an
 * <img> or share with another tab. Token-gated by the customer JWT —
 * the actual download verifies the HMAC instead of the JWT, so the
 * signed URL works without `credentials: 'include'`.
 *
 * Body: `{ ttl?: number }` — TTL in seconds, capped at 1 hour.
 * Default 5 min.
 *
 * Response: `{ url, expiresAt }`
 */
final class SignAction extends BaseAction
{
    private const MAX_TTL = 3600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly DocumentSigner $signer,
    ) {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $documentId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            'SELECT id FROM document WHERE id = :id AND customer_id = :cid LIMIT 1',
        );
        $stmt->execute(['id' => $documentId, 'cid' => $customerId]);
        if ($stmt->fetch() === false) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $body = $request->getParsedBody();
        $ttlRaw = is_array($body) && isset($body['ttl']) ? (int) $body['ttl'] : DocumentSigner::DEFAULT_TTL;
        $ttl = max(30, min(self::MAX_TTL, $ttlRaw));
        $exp = time() + $ttl;
        $sig = $this->signer->sign($documentId, $customerId, $exp);

        $base = $this->resolveBase($request);
        $query = http_build_query([
            'd' => $documentId,
            'c' => $customerId,
            'exp' => $exp,
            'sig' => $sig,
        ]);
        $url = $base . '/documents/sign?' . $query;

        return $this->json($response, 200, [
            'url' => $url,
            'expiresAt' => date(DATE_ATOM, $exp),
        ]);
    }

    private function resolveBase(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
        $host = $uri->getHost();
        $port = $uri->getPort();
        $authority = $host . ($port !== null && !in_array($port, [80, 443], true) ? ':' . $port : '');
        return $scheme . '://' . $authority;
    }
}
