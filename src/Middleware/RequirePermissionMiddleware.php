<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Support\ActiveCompany;

/**
 * Per-route portal permission gate. Checks the permission the login holds
 * **within its active company** (resolved from the JWT `companies` claim + the
 * `X-Act-As-Customer` header via {@see ActiveCompany}) and rejects with 403 when
 * the required key is absent. Admin principals bypass the check (they hold full
 * access). Pre-multi-company tokens fall back to the flat `permissions` claim.
 * Permission keys mirror PORTAL_PERMISSIONS in tds-shared.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $permission)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $claims = is_array($claims) ? $claims : [];

        if ((bool) ($claims['admin'] ?? false) === true) {
            return $handler->handle($request);
        }

        $activeCompany = ActiveCompany::resolve($claims, $request->getHeaderLine(ActiveCompany::HEADER));
        $permissions = ActiveCompany::permissionsFor($claims, $activeCompany);

        if (!in_array($this->permission, $permissions, true)) {
            $r = new Response(403);
            $r->getBody()->write(json_encode([
                'error' => 'Forbidden',
                'detail' => 'Missing permission: ' . $this->permission,
            ]));
            return $r->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
