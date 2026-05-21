<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action\Project;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\Project\ListAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

/**
 * Integration test. Confirms the list endpoint scopes results to the
 * authenticated customer — a regression here would leak other
 * customers' projects.
 */
final class ListActionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run project list integration tests.');
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

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS project');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE project (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              customer_id INT NOT NULL,
              title VARCHAR(200) NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'discovery',
              start_date DATE NULL,
              target_date DATE NULL,
              description TEXT NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            INSERT INTO project (id, customer_id, title, description) VALUES
                (1, 7, 'Mine A', 'd'),
                (2, 7, 'Mine B', 'd'),
                (3, 8, 'Someone else', 'd')
        SQL);
    }

    public function test_returns_only_projects_belonging_to_caller(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => 7]);

        $response = (new ListAction($this->pdo))($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode($response->getBody()->getContents(), true);
        self::assertCount(2, $body['projects']);
        $titles = array_column($body['projects'], 'title');
        self::assertContains('Mine A', $titles);
        self::assertContains('Mine B', $titles);
        self::assertNotContains('Someone else', $titles);
    }

    public function test_returns_empty_list_for_customer_without_projects(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/projects')
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, ['admin' => false, 'customer_id' => 999]);

        $response = (new ListAction($this->pdo))($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode($response->getBody()->getContents(), true);
        self::assertSame([], $body['projects']);
    }
}
