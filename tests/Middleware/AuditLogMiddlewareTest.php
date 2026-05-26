<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Middleware;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Middleware\AuditLogMiddleware;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

final class AuditLogMiddlewareTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run audit log middleware tests.');
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

        $this->pdo->exec('DROP TABLE IF EXISTS audit_log');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE audit_log (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              actor_type VARCHAR(16) NOT NULL,
              actor_id INT UNSIGNED NULL,
              action VARCHAR(8) NOT NULL,
              method VARCHAR(8) NOT NULL,
              path VARCHAR(255) NOT NULL,
              target_type VARCHAR(32) NULL,
              target_id INT UNSIGNED NULL,
              status SMALLINT UNSIGNED NOT NULL,
              ip VARCHAR(45) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function test_no_claims_skips_logging(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/projects');
        $this->dispatch($request, status: 200);

        self::assertSame(0, $this->count());
    }

    public function test_customer_request_writes_row(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects/42', ['REMOTE_ADDR' => '198.51.100.7'])
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => 7]);

        $this->dispatch($request, status: 200);

        $row = $this->pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('customer', $row['actor_type']);
        self::assertSame(7, (int) $row['actor_id']);
        self::assertSame('read', $row['action']);
        self::assertSame('GET', $row['method']);
        self::assertSame('/projects/42', $row['path']);
        self::assertSame('projects', $row['target_type']);
        self::assertSame(42, (int) $row['target_id']);
        self::assertSame(200, (int) $row['status']);
        self::assertSame('198.51.100.7', $row['ip']);
    }

    public function test_admin_request_marked_as_admin_actor(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/messages')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => true, 'admin_id' => 1]);

        $this->dispatch($request, status: 201);

        $row = $this->pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('admin', $row['actor_type']);
        self::assertSame(1, (int) $row['actor_id']);
        self::assertSame('write', $row['action']);
    }

    public function test_x_forwarded_for_takes_precedence_over_remote_addr(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '203.0.113.5, 10.0.0.1')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => 7]);

        $this->dispatch($request, status: 200);

        $row = $this->pdo->query('SELECT ip FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('203.0.113.5', $row['ip']);
    }

    public function test_db_failure_does_not_break_request(): void
    {
        $this->pdo->exec('DROP TABLE audit_log');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => 7]);

        $response = $this->dispatch($request, status: 200);

        self::assertSame(200, $response->getStatusCode(), 'audit failure must not surface to customer');
    }

    private function dispatch(ServerRequestInterface $request, int $status): ResponseInterface
    {
        $handler = new class($status) implements RequestHandlerInterface {
            public function __construct(private int $status) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Response())->withStatus($this->status);
            }
        };
        return (new AuditLogMiddleware($this->pdo))->process($request, $handler);
    }

    private function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    }
}
