<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;

/**
 * Base class for the DB-backed action integration tests. Connects to the
 * real MariaDB named by TDS_TEST_DB_DSN (skips otherwise — SQLite would
 * hide NOW(), enum and rowCount semantics the actions rely on) and offers
 * minimal, FK-free table builders + a request factory that injects JWT
 * claims the way JwksAuthMiddleware does in production.
 *
 * Tables are intentionally minimal (only the columns the actions touch)
 * and standalone — matching the existing Project/ListActionTest style.
 */
abstract class DbTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run action integration tests.');
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
    }

    protected function drop(string $table): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function createCustomerTable(): void
    {
        $this->drop('customer');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE customer (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              email VARCHAR(254) NOT NULL,
              name VARCHAR(200) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    protected function createInvoiceTable(): void
    {
        $this->drop('invoice');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE invoice (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              customer_id INT NOT NULL,
              project_id INT NULL,
              amount_cents INT NOT NULL,
              currency CHAR(3) NOT NULL DEFAULT 'EUR',
              status ENUM('open','paid','void') NOT NULL DEFAULT 'open',
              due_date DATE NOT NULL,
              paid_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    protected function createMessageTable(): void
    {
        $this->drop('message');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE message (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              customer_id INT NOT NULL,
              project_id INT NULL,
              author_type ENUM('customer','owner') NOT NULL,
              body TEXT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              read_at DATETIME NULL,
              edited_at DATETIME NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    /**
     * Build a request carrying JWT claims exactly as JwksAuthMiddleware
     * attaches them after verification.
     *
     * @param array<string,mixed>|null $body parsed JSON body
     * @param array<string,string> $query
     */
    protected function request(
        string $method,
        string $path,
        int $customerId,
        bool $admin = false,
        ?array $body = null,
        array $query = [],
    ): ServerRequestInterface {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(
                JwksAuthMiddleware::ATTR_CLAIMS,
                ['admin' => $admin, 'customer_id' => $customerId],
            )
            ->withQueryParams($query);

        return $body === null ? $req : $req->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    protected function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode($response->getBody()->getContents(), true);
    }
}
