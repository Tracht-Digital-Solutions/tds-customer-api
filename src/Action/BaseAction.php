<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

/**
 * Shared helpers for actions: resolve the effective customer_id, write JSON.
 */
abstract class BaseAction
{
    /**
     * Header carrying the customer an admin is currently viewing in the portal's
     * Admin-Ansicht. Honoured only for admin tokens (see below), so a non-admin
     * echoing it cannot escape their own scope.
     */
    protected const ACTING_CUSTOMER_HEADER = 'X-Act-As-Customer';

    /**
     * The customer this request is scoped to.
     *
     * - Non-admin: fixed by the JWT's `customer_id` (their own account).
     * - Admin (Admin-Ansicht): the `X-Act-As-Customer` header when present,
     *   else the admin's own linked customer if the token carries one. An admin
     *   with neither has no customer to show → 400 (the portal never issues
     *   scoped calls in that state; this is the guard behind it).
     *
     * Throws if the request didn't go through JwksAuthMiddleware (programmer
     * error).
     */
    protected function customerId(ServerRequestInterface $request): int
    {
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        if (!is_array($claims)) {
            throw new \LogicException('JWT claims missing — check that this action is behind JwksAuthMiddleware');
        }

        if ((bool) ($claims['admin'] ?? false) === true) {
            $header = trim($request->getHeaderLine(self::ACTING_CUSTOMER_HEADER));
            if ($header !== '' && ctype_digit($header) && (int) $header > 0) {
                return (int) $header;
            }
            $own = $claims['customer_id'] ?? null;
            if (is_int($own) && $own > 0) {
                return $own;
            }
            throw new HttpBadRequestException($request, 'No customer selected for admin view');
        }

        if (!isset($claims['customer_id']) || !is_int($claims['customer_id'])) {
            throw new \LogicException('JWT claims missing customer_id — check that this action is behind JwksAuthMiddleware');
        }
        return $claims['customer_id'];
    }

    /** @param array<string,mixed> $payload */
    protected function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
