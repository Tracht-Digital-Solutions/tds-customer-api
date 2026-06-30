<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Middleware\RequirePermissionMiddleware;
use Tds\CustomerApi\Tests\Support\StubHandler;

final class RequirePermissionMiddlewareTest extends TestCase
{
    public function test_allows_when_permission_present(): void
    {
        $handler = new StubHandler();
        $response = $this->dispatch(
            ['admin' => false, 'customer_id' => 7, 'permissions' => ['invoices:read', 'invoices:pay']],
            'invoices:pay',
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->reached);
    }

    public function test_rejects_with_403_when_permission_missing(): void
    {
        $handler = new StubHandler();
        $response = $this->dispatch(
            ['admin' => false, 'customer_id' => 7, 'permissions' => ['invoices:read']],
            'invoices:pay',
            $handler,
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->reached);
    }

    public function test_rejects_when_permissions_absent(): void
    {
        $handler = new StubHandler();
        $response = $this->dispatch(['admin' => false, 'customer_id' => 7], 'projects:read', $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_admin_bypasses_check(): void
    {
        $handler = new StubHandler();
        $response = $this->dispatch(['admin' => true], 'invoices:pay', $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->reached);
    }

    /** @param array<string,mixed> $claims */
    private function dispatch(array $claims, string $permission, StubHandler $handler): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invoices')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, $claims);

        return (new RequirePermissionMiddleware($permission))->process($request, $handler);
    }
}
