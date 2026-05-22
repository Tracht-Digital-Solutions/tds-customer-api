<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action\Account;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\Account\GetMeAction;
use Tds\CustomerApi\Action\Account\UpdateMeAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

/**
 * Integration test for GET /me + PATCH /me. Touches the real `customer`
 * table to keep the SQL honest (case-insensitive email matching,
 * `updated_at ON UPDATE CURRENT_TIMESTAMP`, etc.).
 */
final class MeActionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run /me integration tests.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('TDS_TEST_DB_USER') ?: null,
            getenv('TDS_TEST_DB_PASS') ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        $this->pdo->exec('DROP TABLE IF EXISTS customer');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE customer (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              email VARCHAR(254) NOT NULL,
              name VARCHAR(200) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uniq_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->pdo->exec(
            "INSERT INTO customer (id, email, name) VALUES (7, 'jane@example.com', 'Jane Doe')"
        );
    }

    public function test_get_me_returns_authed_customer_profile(): void
    {
        $response = $this->get(customerId: 7);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(7, (int) $body['customer']['id']);
        self::assertSame('jane@example.com', $body['customer']['email']);
        self::assertSame('Jane Doe', $body['customer']['name']);
    }

    public function test_get_me_returns_404_for_missing_customer(): void
    {
        $response = $this->get(customerId: 999);

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_patch_me_updates_name(): void
    {
        $response = $this->patch(customerId: 7, payload: ['name' => 'Jane Renamed']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Jane Renamed', $body['customer']['name']);

        $row = $this->pdo->query('SELECT name FROM customer WHERE id = 7')->fetch();
        self::assertSame('Jane Renamed', $row['name']);
    }

    public function test_patch_me_trims_name(): void
    {
        $response = $this->patch(customerId: 7, payload: ['name' => '   Spaced   ']);

        $body = $this->jsonBody($response);
        self::assertSame('Spaced', $body['customer']['name']);
    }

    public function test_patch_me_empty_name_rejected(): void
    {
        $response = $this->patch(customerId: 7, payload: ['name' => '']);

        self::assertSame(422, $response->getStatusCode());
        $row = $this->pdo->query('SELECT name FROM customer WHERE id = 7')->fetch();
        self::assertSame('Jane Doe', $row['name'], 'name must not be cleared');
    }

    public function test_patch_me_overlong_name_rejected(): void
    {
        $response = $this->patch(customerId: 7, payload: ['name' => str_repeat('x', 201)]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_patch_me_ignores_unknown_fields(): void
    {
        $response = $this->patch(customerId: 7, payload: [
            'name' => 'Jane Two',
            'email' => 'attacker@example.com',
            'id' => 1,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $row = $this->pdo->query('SELECT email, id FROM customer WHERE id = 7')->fetch();
        self::assertSame('jane@example.com', $row['email'], 'email must not be writable here');
        self::assertSame(7, (int) $row['id']);
    }

    public function test_patch_me_empty_body_returns_current_profile(): void
    {
        $response = $this->patch(customerId: 7, payload: []);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Jane Doe', $body['customer']['name']);
    }

    public function test_patch_me_non_array_body_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/me')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => 7]);
        $response = (new UpdateMeAction($this->pdo))($request, new Response());

        self::assertSame(400, $response->getStatusCode());
    }

    private function get(int $customerId): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/me')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => $customerId]);
        return (new GetMeAction($this->pdo))($request, new Response());
    }

    /** @param array<string,mixed> $payload */
    private function patch(int $customerId, array $payload): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/me')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => $customerId])
            ->withParsedBody($payload);
        return (new UpdateMeAction($this->pdo))($request, new Response());
    }

    /** @return array<string,mixed> */
    private function jsonBody(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode($response->getBody()->getContents(), true);
    }
}
