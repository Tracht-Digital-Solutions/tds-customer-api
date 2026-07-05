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

    // --- multi-company: permissions are scoped to the active company ----------

    /** @return array<string,mixed> */
    private function multiCompanyClaims(): array
    {
        return [
            'admin' => false,
            'customer_id' => 3,
            'permissions' => ['invoices:pay'],
            'companies' => [
                ['id' => 3, 'permissions' => ['invoices:pay']],
                ['id' => 5, 'permissions' => ['invoices:read']],
            ],
        ];
    }

    public function test_uses_active_company_permissions_via_header(): void
    {
        $handler = new StubHandler();
        // Acting as company 5, which only has invoices:read → invoices:pay denied.
        $response = $this->dispatch($this->multiCompanyClaims(), 'invoices:pay', $handler, actAs: '5');

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->reached);
    }

    public function test_allows_when_active_company_has_the_permission(): void
    {
        $handler = new StubHandler();
        // Acting as company 3, which has invoices:pay → allowed.
        $response = $this->dispatch($this->multiCompanyClaims(), 'invoices:pay', $handler, actAs: '3');

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->reached);
    }

    public function test_defaults_to_primary_company_without_header(): void
    {
        $handler = new StubHandler();
        // No header → primary company 3 (has invoices:pay).
        $response = $this->dispatch($this->multiCompanyClaims(), 'invoices:pay', $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    /** @param array<string,mixed> $claims */
    private function dispatch(array $claims, string $permission, StubHandler $handler, string $actAs = ''): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invoices')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, $claims);
        if ($actAs !== '') {
            $request = $request->withHeader('X-Act-As-Customer', $actAs);
        }

        return (new RequirePermissionMiddleware($permission))->process($request, $handler);
    }
}
