<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CustomerApi\Bootstrap;

/**
 * Regression test for the CORS preflight through the REAL app (not the
 * middleware in isolation): Slim middleware is LIFO, so when CorsMiddleware
 * was added before addRoutingMiddleware() the routing middleware ran first
 * and 405'd every OPTIONS request (no OPTIONS routes are registered) before
 * CORS could short-circuit it — browsers then blocked every cross-origin
 * JSON/Authorization/X-Act-As-Customer request from both panels.
 */
final class PreflightTest extends TestCase
{
    private const ORIGIN = 'https://app.tracht-digital.de';

    protected function setUp(): void
    {
        // createApp() eagerly resolves JwksClient (for the auth middlewares)
        // and the error middleware reads APP_ENV; PDO stays lazy.
        $_ENV['APP_ENV'] = 'test';
        $_ENV['AUTH_API_URL'] = 'http://127.0.0.1:9';
        $_ENV['CORS_ALLOWED_ORIGINS'] = self::ORIGIN;
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV'], $_ENV['AUTH_API_URL'], $_ENV['CORS_ALLOWED_ORIGINS']);
    }

    public function test_options_preflight_returns_204_with_cors_headers(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/me')
            ->withHeader('Origin', self::ORIGIN)
            ->withHeader('Access-Control-Request-Method', 'GET')
            ->withHeader('Access-Control-Request-Headers', 'x-act-as-customer');

        $response = $app->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('X-Act-As-Customer', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }
}
