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

    protected function createTicketStatusTable(): void
    {
        $this->drop('ticket_status');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE ticket_status (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name VARCHAR(80) NOT NULL,
              color VARCHAR(20) NOT NULL DEFAULT 'neutral',
              sort_order INT NOT NULL DEFAULT 0,
              visible_to_customer TINYINT(1) NOT NULL DEFAULT 1,
              is_terminal TINYINT(1) NOT NULL DEFAULT 0,
              is_default TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    /** Seed the three canonical statuses (default / internal-hidden / terminal). */
    protected function seedTicketStatuses(): void
    {
        $this->pdo->exec(
            "INSERT INTO ticket_status (id, name, color, sort_order, visible_to_customer, is_terminal, is_default) VALUES "
            . "(1, 'Offen', 'warning', 10, 1, 0, 1),"
            . "(2, 'Intern prüfen', 'neutral', 20, 0, 0, 0),"
            . "(3, 'Gelöst', 'success', 30, 1, 1, 0)"
        );
    }

    protected function createTicketTable(): void
    {
        $this->drop('ticket');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE ticket (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              customer_id INT UNSIGNED NULL,
              project_id INT UNSIGNED NULL,
              status_id INT UNSIGNED NOT NULL,
              subject VARCHAR(200) NOT NULL,
              description TEXT NOT NULL,
              priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
              type ENUM('question','bug','feature','other','contact') NOT NULL DEFAULT 'question',
              assignee_user_id INT UNSIGNED NULL,
              created_by_type ENUM('customer','owner') NOT NULL,
              created_by_user_id INT UNSIGNED NULL,
              source ENUM('portal','email','contact') NOT NULL DEFAULT 'portal',
              email_message_id VARCHAR(255) NULL,
              customer_action_required TINYINT(1) NOT NULL DEFAULT 0,
              customer_action_note TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              closed_at DATETIME NULL,
              from_name VARCHAR(200) NULL,
              from_email VARCHAR(254) NULL,
              from_company VARCHAR(200) NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    protected function createTicketCommentTable(): void
    {
        $this->drop('ticket_comment');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE ticket_comment (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              ticket_id INT UNSIGNED NOT NULL,
              author_type ENUM('customer','owner') NOT NULL,
              author_user_id INT UNSIGNED NULL,
              body TEXT NOT NULL,
              is_internal TINYINT(1) NOT NULL DEFAULT 0,
              email_message_id VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              edited_at DATETIME NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    protected function createTicketAttachmentTable(): void
    {
        $this->drop('ticket_attachment');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE ticket_attachment (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              ticket_id INT UNSIGNED NOT NULL,
              comment_id INT UNSIGNED NULL,
              filename VARCHAR(255) NOT NULL,
              storage_path VARCHAR(500) NOT NULL,
              mime_type VARCHAR(150) NOT NULL,
              size_bytes INT UNSIGNED NOT NULL,
              uploaded_by_type ENUM('customer','owner') NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    protected function createTicketSettingTable(): void
    {
        $this->drop('ticket_setting');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE ticket_setting (
              setting_key VARCHAR(60) NOT NULL,
              setting_value VARCHAR(255) NOT NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (setting_key)
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
        ?int $uid = null,
    ): ServerRequestInterface {
        $claims = ['admin' => $admin, 'customer_id' => $customerId];
        if ($uid !== null) {
            $claims['uid'] = $uid;
        }
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(JwksAuthMiddleware::ATTR_CLAIMS, $claims)
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
