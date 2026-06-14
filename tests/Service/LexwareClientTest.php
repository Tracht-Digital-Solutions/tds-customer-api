<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Service\LexwareClient;
use Tds\CustomerApi\Service\LexwareException;

final class LexwareClientTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $history = [];

    private function client(Response $response): LexwareClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));
        return new LexwareClient(new Client(['handler' => $stack]), 'key-123', 'https://api.lexware.io/v1');
    }

    public function test_not_configured_without_api_key(): void
    {
        $c = new LexwareClient(new Client(), '', 'https://api.lexware.io/v1');
        self::assertFalse($c->isConfigured());
    }

    public function test_create_invoice_returns_body_on_201(): void
    {
        $c = $this->client(new Response(201, [], (string) json_encode([
            'id' => 'abc-123',
            'resourceUri' => 'https://api.lexware.io/v1/invoices/abc-123',
        ])));

        $res = $c->createInvoice(['lineItems' => []], false);

        self::assertSame('abc-123', $res['id']);
        $url = (string) $this->history[0]['request']->getUri();
        self::assertSame('https://api.lexware.io/v1/invoices', $url);
        self::assertSame('Bearer key-123', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_finalize_appends_query_parameter(): void
    {
        $c = $this->client(new Response(201, [], (string) json_encode(['id' => 'x'])));
        $c->createInvoice(['lineItems' => []], true);
        $url = (string) $this->history[0]['request']->getUri();
        self::assertStringContainsString('finalize=true', $url);
    }

    public function test_401_throws_with_status_and_hint(): void
    {
        $c = $this->client(new Response(401, [], (string) json_encode(['message' => 'unauthorized'])));
        try {
            $c->createInvoice(['lineItems' => []], false);
            self::fail('expected LexwareException');
        } catch (LexwareException $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertStringContainsString('API-Key', $e->getMessage());
        }
    }

    public function test_201_without_id_throws(): void
    {
        $c = $this->client(new Response(201, [], (string) json_encode(['resourceUri' => 'x'])));
        $this->expectException(LexwareException::class);
        $c->createInvoice(['lineItems' => []], false);
    }
}
