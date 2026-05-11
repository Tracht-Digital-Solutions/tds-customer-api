<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Middleware;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records one row per authenticated request in `audit_log` for
 * GDPR/operations visibility.
 *
 * Sits INSIDE the auth group (so it only fires after JwksAuthMiddleware
 * attached the JWT claims) and runs AFTER the handler so it captures
 * the final HTTP status. Logging failures are swallowed — we never
 * want to fail a customer-facing request just because the audit
 * write hit a transient DB hiccup.
 *
 * Retention is left to a cron job pruning rows older than 90 days
 * (see issue #8).
 */
final class AuditLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
            if (!is_array($claims)) {
                return $response;
            }
            [$actorType, $actorId] = $this->actor($claims);
            [$targetType, $targetId] = $this->target($request);

            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_log (actor_type, actor_id, action, method, path, target_type, target_id, status, ip) '
                . 'VALUES (:at, :ai, :act, :m, :p, :tt, :ti, :s, :ip)',
            );
            $stmt->execute([
                'at' => $actorType,
                'ai' => $actorId,
                'act' => $request->getMethod() === 'GET' ? 'read' : 'write',
                'm' => $request->getMethod(),
                'p' => $request->getUri()->getPath(),
                'tt' => $targetType,
                'ti' => $targetId,
                's' => $response->getStatusCode(),
                'ip' => $this->clientIp($request),
            ]);
        } catch (\Throwable) {
            // Best-effort: swallow logging failures so we never 5xx
            // a real customer request because audit_log is unhappy.
        }

        return $response;
    }

    /** @param array<string,mixed> $claims @return array{0:string,1:?int} */
    private function actor(array $claims): array
    {
        if (($claims['admin'] ?? false) === true) {
            $id = $claims['admin_id'] ?? null;
            return ['admin', is_int($id) ? $id : null];
        }
        $id = $claims['customer_id'] ?? null;
        return ['customer', is_int($id) ? $id : null];
    }

    /**
     * Extract a (target_type, target_id) tuple from the request path
     * for common resource patterns (`/projects/123`, `/invoices/123`,
     * `/documents/123/...`). Returns nulls for non-resource paths
     * (`/messages`, `/invoices`).
     *
     * @return array{0:?string,1:?int}
     */
    private function target(ServerRequestInterface $request): array
    {
        $path = trim($request->getUri()->getPath(), '/');
        if ($path === '') return [null, null];

        $parts = explode('/', $path);
        if (!isset($parts[0]) || !isset($parts[1])) return [$parts[0] ?? null, null];

        $type = $parts[0];
        $id = $parts[1];
        return [$type, ctype_digit($id) ? (int) $id : null];
    }

    private function clientIp(ServerRequestInterface $request): ?string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            // First entry is the original client; subsequent are proxies.
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') return $first;
        }
        $server = $request->getServerParams();
        return isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null;
    }
}
