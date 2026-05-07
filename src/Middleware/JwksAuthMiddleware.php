<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Service\JwksClient;

/**
 * Verifies the Authorization Bearer JWT against the JWKS served by
 * tds-auth-api. On success, attaches the decoded claims to the
 * request so downstream actions can read $request->getAttribute('claims').
 *
 * Customer endpoints require admin=false + customer_id present.
 * Admin endpoints (none yet in this service) require admin=true.
 */
final class JwksAuthMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'tds_session';
    public const ATTR_CLAIMS = 'claims';

    public function __construct(private readonly JwksClient $jwks)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->unauthorized('No token presented');
        }

        try {
            $claims = $this->jwks->verify($token);
        } catch (\Throwable $e) {
            return $this->unauthorized('Invalid token: ' . $e->getMessage());
        }

        $isAdmin = (bool) ($claims['admin'] ?? false);
        $customerId = $claims['customer_id'] ?? null;

        if (!$isAdmin && (!is_int($customerId) || $customerId <= 0)) {
            return $this->unauthorized('Token has no customer_id');
        }

        $request = $request->withAttribute(self::ATTR_CLAIMS, $claims);
        return $handler->handle($request);
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        $cookie = $request->getCookieParams()[self::COOKIE_NAME] ?? null;
        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    private function unauthorized(string $detail): ResponseInterface
    {
        $r = new Response(401);
        $r->getBody()->write(json_encode(['error' => 'Unauthorized', 'detail' => $detail]));
        return $r->withHeader('Content-Type', 'application/json');
    }
}
