<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CustomerApi\Middleware\CorsMiddleware;
use Tds\CustomerApi\Tests\Support\StubHandler;

final class CorsMiddlewareTest extends TestCase
{
    private const ALLOWED = ['https://app.tracht-digital.de'];

    private function request(string $method, ?string $origin): \Psr\Http\Message\ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, '/me');
        return $origin === null ? $r : $r->withHeader('Origin', $origin);
    }

    public function test_preflight_returns_204_without_reaching_handler(): void
    {
        $handler = new StubHandler();
        $res = (new CorsMiddleware(self::ALLOWED))->process($this->request('OPTIONS', self::ALLOWED[0]), $handler);

        self::assertSame(204, $res->getStatusCode());
        self::assertFalse($handler->reached);
    }

    public function test_allowed_origin_gets_credentials_for_cookie_auth(): void
    {
        $res = (new CorsMiddleware(self::ALLOWED))->process($this->request('GET', self::ALLOWED[0]), new StubHandler());

        self::assertSame(self::ALLOWED[0], $res->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $res->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $res->getHeaderLine('Vary'));
    }

    public function test_disallowed_origin_gets_neither_origin_nor_credentials(): void
    {
        $res = (new CorsMiddleware(self::ALLOWED))->process($this->request('GET', 'https://evil.example'), new StubHandler());

        self::assertSame('', $res->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $res->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function test_advertises_patch_for_the_portal_update_endpoints(): void
    {
        $res = (new CorsMiddleware(self::ALLOWED))->process($this->request('GET', null), new StubHandler());

        self::assertStringContainsString('PATCH', $res->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('600', $res->getHeaderLine('Access-Control-Max-Age'));
    }
}
