<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action\Ticket;

use GuzzleHttp\Client;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\Admin\Ticket\CommentAction as AdminCommentAction;
use Tds\CustomerApi\Action\Admin\Ticket\GetAction as AdminGetAction;
use Tds\CustomerApi\Action\Admin\Ticket\ListAction as AdminListAction;
use Tds\CustomerApi\Action\Admin\Ticket\UpdateAction as AdminUpdateAction;
use Tds\CustomerApi\Action\Admin\TicketStatus\DeleteAction as StatusDeleteAction;
use Tds\CustomerApi\Action\Admin\TicketStatus\ListAction as StatusListAction;
use Tds\CustomerApi\Action\Ticket\CommentAction;
use Tds\CustomerApi\Action\Ticket\CreateAction;
use Tds\CustomerApi\Action\Ticket\GetAction;
use Tds\CustomerApi\Action\Ticket\ListAction;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;
use Tds\CustomerApi\Service\TicketStatusRepository;
use Tds\CustomerApi\Tests\Support\DbTestCase;

/**
 * DB-backed integration tests for the ticket workflow. Skips unless
 * TDS_TEST_DB_DSN points at a real MariaDB/MySQL (the actions lean on NOW(),
 * ENUM and JOIN semantics SQLite would mask).
 */
final class TicketActionsTest extends DbTestCase
{
    private TicketRepository $tickets;
    private TicketStatusRepository $statuses;
    private TicketSettings $settings;
    private TicketMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerTable();
        $this->createTicketStatusTable();
        $this->createTicketTable();
        $this->createTicketCommentTable();
        $this->createTicketAttachmentTable();
        $this->createTicketSettingTable();
        $this->seedTicketStatuses();
        $this->pdo->exec("INSERT INTO customer (id, email, name) VALUES (7, 'c@example.com', 'Acme GmbH')");

        $this->tickets = new TicketRepository($this->pdo);
        $this->statuses = new TicketStatusRepository($this->pdo);
        $this->settings = new TicketSettings($this->pdo);
        // Empty apiKey → mailer no-ops, no HTTP calls in tests.
        $this->mailer = new TicketMailer(new Client(), '', 'from@x', 'admin@x', 'https://m', 'https://a');
    }

    private function createTicket(int $customerId = 7): int
    {
        $action = new CreateAction($this->tickets, $this->statuses, $this->settings, $this->mailer, $this->pdo);
        $req = $this->request('POST', '/tickets', $customerId, body: [
            'subject' => 'Login klappt nicht',
            'description' => 'Ich komme seit heute nicht mehr rein.',
            'priority' => 'high',
        ], uid: 55);
        $res = $action($req, new Response());
        self::assertSame(201, $res->getStatusCode());
        return (int) $this->json($res)['id'];
    }

    public function test_customer_creates_and_lists_ticket(): void
    {
        $id = $this->createTicket();

        $res = (new ListAction($this->tickets))($this->request('GET', '/tickets', 7), new Response());
        $body = $this->json($res);
        self::assertCount(1, $body['tickets']);
        self::assertSame($id, $body['tickets'][0]['id']);
        self::assertSame('high', $body['tickets'][0]['priority']);
        // Default status (Offen) is visible.
        self::assertSame('Offen', $body['tickets'][0]['status']['name']);
    }

    public function test_create_rejects_short_subject(): void
    {
        $action = new CreateAction($this->tickets, $this->statuses, $this->settings, $this->mailer, $this->pdo);
        $req = $this->request('POST', '/tickets', 7, body: ['subject' => 'hi', 'description' => 'long enough here']);
        self::assertSame(422, $action($req, new Response())->getStatusCode());
    }

    public function test_internal_status_shows_fallback_to_customer(): void
    {
        $id = $this->createTicket();
        // Move to the internal-hidden status (id 2).
        $this->pdo->exec("UPDATE ticket SET status_id = 2 WHERE id = {$id}");

        $res = (new GetAction($this->tickets))(
            $this->request('GET', "/tickets/{$id}", 7),
            new Response(),
            ['id' => (string) $id],
        );
        $ticket = $this->json($res)['ticket'];
        self::assertFalse($ticket['status']['visibleToCustomer']);
        self::assertSame('In Bearbeitung', $ticket['status']['name']);
    }

    public function test_foreign_ticket_is_not_visible(): void
    {
        $id = $this->createTicket(7);
        // Customer 99 must not see customer 7's ticket.
        $res = (new GetAction($this->tickets))(
            $this->request('GET', "/tickets/{$id}", 99),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(404, $res->getStatusCode());
    }

    public function test_customer_reply_clears_customer_action(): void
    {
        $id = $this->createTicket();
        $this->pdo->exec("UPDATE ticket SET customer_action_required = 1, customer_action_note = 'Bitte Screenshot' WHERE id = {$id}");

        $res = (new CommentAction($this->tickets))(
            $this->request('POST', "/tickets/{$id}/comments", 7, body: ['body' => 'Hier der Screenshot'], uid: 55),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(201, $res->getStatusCode());

        $flag = $this->pdo->query("SELECT customer_action_required FROM ticket WHERE id = {$id}")->fetchColumn();
        self::assertSame(0, (int) $flag);
    }

    public function test_admin_internal_note_hidden_from_customer(): void
    {
        $id = $this->createTicket();

        // Admin adds a public reply + an internal note.
        $adminComment = new AdminCommentAction($this->tickets, $this->settings, $this->mailer, $this->pdo);
        $adminComment(
            $this->request('POST', "/admin/tickets/{$id}/comments", 0, admin: true, body: ['body' => 'Wir schauen es an'], uid: 1),
            new Response(),
            ['id' => (string) $id],
        );
        $adminComment(
            $this->request('POST', "/admin/tickets/{$id}/comments", 0, admin: true, body: ['body' => 'Bug im Auth-Service', 'isInternal' => true], uid: 1),
            new Response(),
            ['id' => (string) $id],
        );

        // Customer sees only the public reply.
        $custRes = (new GetAction($this->tickets))(
            $this->request('GET', "/tickets/{$id}", 7),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertCount(1, $this->json($custRes)['comments']);

        // Admin sees both.
        $adminRes = (new AdminGetAction($this->tickets))(
            $this->request('GET', "/admin/tickets/{$id}", 0, admin: true),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertCount(2, $this->json($adminRes)['comments']);
    }

    public function test_admin_terminal_status_sets_closed_at(): void
    {
        $id = $this->createTicket();

        $update = new AdminUpdateAction($this->tickets, $this->statuses, $this->settings, $this->mailer, $this->pdo);
        $res = $update(
            $this->request('PATCH', "/admin/tickets/{$id}", 0, admin: true, body: ['statusId' => 3]),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $res->getStatusCode());
        self::assertNotNull($this->json($res)['ticket']['closedAt']);
    }

    public function test_admin_assign_and_filter(): void
    {
        $id = $this->createTicket();

        $update = new AdminUpdateAction($this->tickets, $this->statuses, $this->settings, $this->mailer, $this->pdo);
        $update(
            $this->request('PATCH', "/admin/tickets/{$id}", 0, admin: true, body: ['assigneeUserId' => 42]),
            new Response(),
            ['id' => (string) $id],
        );

        $res = (new AdminListAction($this->tickets))(
            $this->request('GET', '/admin/tickets', 0, admin: true, query: ['assigneeUserId' => '42']),
            new Response(),
        );
        $tickets = $this->json($res)['tickets'];
        self::assertCount(1, $tickets);
        self::assertSame(42, $tickets[0]['assigneeUserId']);
        self::assertSame('Acme GmbH', $tickets[0]['customerName']);
    }

    public function test_status_delete_in_use_is_refused(): void
    {
        $this->createTicket(); // occupies status 1

        $res = (new StatusDeleteAction($this->statuses))(
            $this->request('DELETE', '/admin/ticket-statuses/1', 0, admin: true),
            new Response(),
            ['id' => '1'],
        );
        self::assertSame(409, $res->getStatusCode());
    }

    public function test_status_list_returns_registry(): void
    {
        $res = (new StatusListAction($this->statuses))(
            $this->request('GET', '/admin/ticket-statuses', 0, admin: true),
            new Response(),
        );
        self::assertCount(3, $this->json($res)['statuses']);
    }
}
