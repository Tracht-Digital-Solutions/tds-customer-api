<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action\Ticket;

use Slim\Psr7\Response;
use Tds\CustomerApi\Action\Admin\Ticket\ListAction as AdminListAction;
use Tds\CustomerApi\Action\Ticket\ContactIngestAction;
use Tds\CustomerApi\Service\AppSettings;
use Tds\CustomerApi\Service\SmtpMailer;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;
use Tds\CustomerApi\Service\TicketStatusRepository;
use Tds\CustomerApi\Tests\Support\DbTestCase;

/**
 * DB-backed integration tests for the contact-form → ticket ingest
 * (POST /tickets/contact). Skips unless TDS_TEST_DB_DSN points at a real
 * MariaDB/MySQL (the create path leans on the widened ENUM + nullable
 * customer_id SQLite would mask).
 */
final class ContactIngestActionTest extends DbTestCase
{
    private const TOKEN = 'contact-secret-token';

    private TicketRepository $tickets;
    private TicketStatusRepository $statuses;
    private TicketSettings $ticketSettings;
    private TicketMailer $mailer;
    private AppSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerTable();
        $this->createTicketStatusTable();
        $this->createTicketTable();
        $this->createTicketCommentTable();
        $this->createTicketSettingTable();
        $this->seedTicketStatuses();
        $this->pdo->exec("INSERT INTO customer (id, email, name) VALUES (7, 'known@example.com', 'Acme GmbH')");

        $this->tickets = new TicketRepository($this->pdo);
        $this->statuses = new TicketStatusRepository($this->pdo);
        $this->ticketSettings = new TicketSettings($this->pdo);
        $smtp = new SmtpMailer(host: '', port: '', user: '', pass: '', security: 'tls', from: '');
        $this->mailer = new TicketMailer($smtp, 'admin@x', 'support@x', 'https://m', 'https://a');
        // No app_setting table here → AppSettings falls back to the env var.
        putenv('INGEST_TOKEN=' . self::TOKEN);
        $_ENV['INGEST_TOKEN'] = self::TOKEN;
        $this->settings = new AppSettings($this->pdo);
    }

    protected function tearDown(): void
    {
        putenv('INGEST_TOKEN');
        unset($_ENV['INGEST_TOKEN']);
        parent::tearDown();
    }

    private function action(): ContactIngestAction
    {
        return new ContactIngestAction(
            $this->tickets,
            $this->statuses,
            $this->mailer,
            $this->ticketSettings,
            $this->settings,
        );
    }

    /** @param array<string,mixed> $body */
    private function post(array $body, string $token = self::TOKEN): Response
    {
        $req = $this->request('POST', '/tickets/contact', 0, body: $body, query: ['token' => $token]);
        return ($this->action())($req, new Response());
    }

    public function test_rejects_invalid_token(): void
    {
        $res = $this->post([
            'name' => 'Max Mustermann',
            'email' => 'max@example.org',
            'message' => 'Ich hätte gern ein Angebot für einen Onlineshop.',
        ], token: 'wrong');
        self::assertSame(401, $res->getStatusCode());
    }

    public function test_rejects_invalid_payload(): void
    {
        $res = $this->post([
            'name' => 'M',            // too short
            'email' => 'not-an-email',
            'message' => 'zu kurz',    // < 20 chars
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function test_creates_contact_ticket_without_customer(): void
    {
        $res = $this->post([
            'name' => 'Max Mustermann',
            'email' => 'max@example.org',
            'company' => 'Muster GmbH',
            'message' => 'Ich hätte gern ein Angebot für einen Onlineshop mit Anbindung.',
        ]);
        self::assertSame(201, $res->getStatusCode());
        $id = (int) $this->json($res)['id'];

        $row = $this->pdo->query("SELECT * FROM ticket WHERE id = {$id}")->fetch();
        self::assertNull($row['customer_id']);
        self::assertSame('contact', $row['type']);
        self::assertSame('contact', $row['source']);
        self::assertSame('Max Mustermann', $row['from_name']);
        self::assertSame('max@example.org', $row['from_email']);
        self::assertSame('Muster GmbH', $row['from_company']);
        self::assertStringContainsString('Max Mustermann', (string) $row['subject']);

        // Admin list surfaces it (LEFT JOIN) with the submitter as display name,
        // filterable by type=contact.
        $list = (new AdminListAction($this->tickets))(
            $this->request('GET', '/admin/tickets', 0, admin: true, query: ['type' => 'contact']),
            new Response(),
        );
        $tickets = $this->json($list)['tickets'];
        self::assertCount(1, $tickets);
        self::assertNull($tickets[0]['customerId']);
        self::assertSame('Max Mustermann', $tickets[0]['customerName']);
        self::assertSame('max@example.org', $tickets[0]['fromEmail']);
    }

    public function test_binds_to_existing_customer_on_email_match(): void
    {
        $res = $this->post([
            'name' => 'Acme Support',
            'email' => 'known@example.com',
            'message' => 'Wir bräuchten Unterstützung bei der bestehenden Integration.',
        ]);
        self::assertSame(201, $res->getStatusCode());
        $id = (int) $this->json($res)['id'];

        $customerId = $this->pdo->query("SELECT customer_id FROM ticket WHERE id = {$id}")->fetchColumn();
        self::assertSame(7, (int) $customerId);
    }
}
