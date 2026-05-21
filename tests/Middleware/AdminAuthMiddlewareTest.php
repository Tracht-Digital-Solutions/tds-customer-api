<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Middleware\AdminAuthMiddleware;

final class AdminAuthMiddlewareTest extends TestCase
{
    public function test_missing_header_returns_401(): void
    {
        $response = $this->run(new AdminAuthMiddleware('expected'), bearer: null);

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_wrong_token_returns_401(): void
    {
        $response = $this->run(new AdminAuthMiddleware('expected'), bearer: 'nope');

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_unconfigured_returns_401_with_detail(): void
    {
        $response = $this->run(new AdminAuthMiddleware(''), bearer: 'whatever');

        self::assertSame(401, $response->getStatusCode());
        $response->getBody()->rewind();
        self::assertSame(
            ['error' => 'admin token not configured'],
            json_decode($response->getBody()->getContents(), true),
        );
    }

    public function test_correct_token_passes_through(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/x')
            ->withHeader('Authorization', 'Bearer expected');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };

        $response = (new AdminAuthMiddleware('expected'))->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    private function run(AdminAuthMiddleware $mw, ?string $bearer): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/x');
        if ($bearer !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearer);
        }
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };
        return $mw->process($request, $handler);
    }
}
