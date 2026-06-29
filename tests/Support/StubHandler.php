<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Minimal PSR-15 handler for middleware tests: returns a fixed response
 * (200 by default) and records whether it was reached.
 */
final class StubHandler implements RequestHandlerInterface
{
    public bool $reached = false;

    public function __construct(private readonly int $status = 200)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->reached = true;
        return new Response($this->status);
    }
}
