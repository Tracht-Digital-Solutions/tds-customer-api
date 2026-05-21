<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

final class BaseActionTest extends TestCase
{
    public function test_customer_id_returned_when_claims_attached(): void
    {
        $action = new ProbeAction();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['customer_id' => 7, 'admin' => false]);

        self::assertSame(7, $action->probeCustomerId($request));
    }

    public function test_customer_id_throws_when_claims_missing(): void
    {
        $action = new ProbeAction();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/probe');

        $this->expectException(\LogicException::class);
        $action->probeCustomerId($request);
    }

    public function test_customer_id_throws_when_claim_has_wrong_type(): void
    {
        $action = new ProbeAction();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['customer_id' => '7']);

        $this->expectException(\LogicException::class);
        $action->probeCustomerId($request);
    }

    public function test_json_writes_payload_with_status_and_content_type(): void
    {
        $action = new ProbeAction();
        $response = $action->probeJson(new Response(), 418, ['ok' => true]);

        self::assertSame(418, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        self::assertSame(['ok' => true], json_decode($response->getBody()->getContents(), true));
    }
}

final class ProbeAction extends BaseAction
{
    public function probeCustomerId(ServerRequestInterface $request): int
    {
        return $this->customerId($request);
    }

    /** @param array<string,mixed> $payload */
    public function probeJson(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        return $this->json($response, $status, $payload);
    }
}
