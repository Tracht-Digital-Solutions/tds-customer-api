<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

/**
 * Shared helpers for actions: extract customer_id from JWT claims,
 * write JSON responses.
 */
abstract class BaseAction
{
    /**
     * Pulls customer_id from the JWT claims attached by JwksAuthMiddleware.
     * Throws if the request didn't go through the middleware (programmer error).
     */
    protected function customerId(ServerRequestInterface $request): int
    {
        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        if (!is_array($claims) || !isset($claims['customer_id']) || !is_int($claims['customer_id'])) {
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
