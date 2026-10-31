<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Bearer-token gate for the `/admin/*` endpoints. These run server-
 * to-server (admin panel → here), not customer-driven, so they don't
 * use the JWKS-verified customer JWT — they use the same shared
 * ADMIN_TOKEN that admin uses for tds-content-api writes.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $expectedToken)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->expectedToken === '') {
            return $this->unauthorized('admin token not configured');
        }

        $auth = $request->getHeaderLine('Authorization');
        if ($auth === '' || preg_match('/^Bearer\s+(.+)$/i', $auth, $m) !== 1) {
            return $this->unauthorized();
        }
        if (!hash_equals($this->expectedToken, $m[1])) {
            return $this->unauthorized();
        }

        return $handler->handle($request);
    }

    private function unauthorized(string $detail = 'unauthorized'): ResponseInterface
    {
        $r = new Response(401);
        $r->getBody()->write(json_encode(['error' => $detail]));
        return $r->withHeader('Content-Type', 'application/json');
    }
}
