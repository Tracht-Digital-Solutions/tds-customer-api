<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Tests\Support\FakeTokenVerifier;

final class JwksAuthMiddlewareTest extends TestCase
{
    private FakeTokenVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new FakeTokenVerifier();
    }

    public function test_missing_token_returns_401(): void
    {
        $response = $this->dispatch((new ServerRequestFactory())->createServerRequest('GET', '/projects'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('No token presented', $this->jsonBody($response)['detail']);
    }

    public function test_invalid_token_returns_401_with_reason(): void
    {
        $this->verifier->throwOnVerify = new \RuntimeException('expired');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withHeader('Authorization', 'Bearer token');

        $response = $this->dispatch($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('expired', $this->jsonBody($response)['detail']);
    }

    public function test_customer_token_without_customer_id_returns_401(): void
    {
        $this->verifier->claims = ['admin' => false];
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withHeader('Authorization', 'Bearer token');

        $response = $this->dispatch($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Token has no customer_id', $this->jsonBody($response)['detail']);
    }

    public function test_customer_token_with_customer_id_attaches_claims_and_proceeds(): void
    {
        $this->verifier->claims = ['admin' => false, 'customer_id' => 7];

        $captured = null;
        $handler = new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?array &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
                return (new Response())->withStatus(200);
            }
        };

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withHeader('Authorization', 'Bearer the.real.token');

        $response = (new JwksAuthMiddleware($this->verifier))->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['admin' => false, 'customer_id' => 7], $captured);
        self::assertSame('the.real.token', $this->verifier->lastToken);
    }

    public function test_admin_token_proceeds_even_without_customer_id(): void
    {
        $this->verifier->claims = ['admin' => true];
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withHeader('Authorization', 'Bearer token');

        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_token_from_cookie_when_no_bearer(): void
    {
        $this->verifier->claims = ['admin' => false, 'customer_id' => 7];

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withCookieParams([JwksAuthMiddleware::COOKIE_NAME => 'cookie-token']);

        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cookie-token', $this->verifier->lastToken);
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Response())->withStatus(200);
            }
        };
        return (new JwksAuthMiddleware($this->verifier))->process($request, $handler);
    }

    /** @return array<string,mixed> */
    private function jsonBody(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode($response->getBody()->getContents(), true);
    }
}
