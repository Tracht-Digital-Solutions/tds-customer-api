<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action\Message;

use Slim\Psr7\Response;
use Tds\CustomerApi\Action\Message\CreateAction;
use Tds\CustomerApi\Action\Message\ListAction;
use Tds\CustomerApi\Action\Message\UpdateAction;
use Tds\CustomerApi\Tests\Support\DbTestCase;

/**
 * Integration tests for the message thread endpoints. Covers body
 * validation, author_type derivation from the admin claim, customer
 * scoping on read, and — most importantly — the edit ownership rules:
 * a customer may only edit their own author_type='customer' messages,
 * and a non-matching update returns 404 (so message IDs aren't leakable).
 */
final class MessageActionsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createMessageTable();
    }

    private function seed(): void
    {
        $this->pdo->exec(<<<'SQL'
            INSERT INTO message (id, customer_id, project_id, author_type, body, created_at) VALUES
              (1, 7, NULL, 'customer', 'first',  '2026-01-01 09:00:00'),
              (2, 7, 5,    'owner',    'reply',  '2026-01-01 10:00:00'),
              (3, 8, NULL, 'customer', 'other',  '2026-01-01 11:00:00')
        SQL);
    }

    // --- CreateAction ---------------------------------------------------

    public function test_create_inserts_a_customer_message_and_returns_id(): void
    {
        $req = $this->request('POST', '/messages', 7, body: ['body' => 'Hello there']);
        $res = (new CreateAction($this->pdo))($req, new Response());

        self::assertSame(201, $res->getStatusCode());
        $id = $this->json($res)['id'];
        $row = $this->pdo->query("SELECT author_type, body FROM message WHERE id = {$id}")->fetch();
        self::assertSame('customer', $row['author_type']);
        self::assertSame('Hello there', $row['body']);
    }

    public function test_create_by_admin_is_authored_as_owner(): void
    {
        $req = $this->request('POST', '/messages', 7, admin: true, body: ['body' => 'Owner note', 'projectId' => '5']);
        $res = (new CreateAction($this->pdo))($req, new Response());

        $id = $this->json($res)['id'];
        $row = $this->pdo->query("SELECT author_type, project_id FROM message WHERE id = {$id}")->fetch();
        self::assertSame('owner', $row['author_type']);
        self::assertSame(5, (int) $row['project_id']);
    }

    public function test_create_rejects_empty_body_with_422(): void
    {
        $req = $this->request('POST', '/messages', 7, body: ['body' => '   ']);
        $res = (new CreateAction($this->pdo))($req, new Response());
        self::assertSame(422, $res->getStatusCode());
    }

    public function test_create_rejects_non_array_body_with_400(): void
    {
        $req = $this->request('POST', '/messages', 7); // no parsed body
        $res = (new CreateAction($this->pdo))($req, new Response());
        self::assertSame(400, $res->getStatusCode());
    }

    // --- ListAction -----------------------------------------------------

    public function test_list_is_scoped_to_the_caller_and_ordered_oldest_first(): void
    {
        $this->seed();
        $res = (new ListAction($this->pdo))($this->request('GET', '/messages', 7), new Response());

        $messages = $this->json($res)['messages'];
        self::assertCount(2, $messages);
        self::assertSame([1, 2], array_map('intval', array_column($messages, 'id')));
    }

    public function test_list_filters_by_project_id_when_given(): void
    {
        $this->seed();
        $req = $this->request('GET', '/messages', 7, query: ['projectId' => '5']);
        $res = (new ListAction($this->pdo))($req, new Response());

        $messages = $this->json($res)['messages'];
        self::assertCount(1, $messages);
        self::assertSame(2, (int) $messages[0]['id']);
    }

    // --- UpdateAction (ownership) ---------------------------------------

    public function test_customer_can_edit_their_own_message(): void
    {
        $this->seed();
        $req = $this->request('PATCH', '/messages/1', 7, body: ['body' => 'edited']);
        $res = (new UpdateAction($this->pdo))($req, new Response(), ['id' => '1']);

        self::assertSame(200, $res->getStatusCode());
        $row = $this->pdo->query('SELECT body, edited_at FROM message WHERE id = 1')->fetch();
        self::assertSame('edited', $row['body']);
        self::assertNotNull($row['edited_at']);
    }

    public function test_customer_cannot_edit_an_owner_message_404(): void
    {
        $this->seed();
        $req = $this->request('PATCH', '/messages/2', 7, body: ['body' => 'hijack']);
        $res = (new UpdateAction($this->pdo))($req, new Response(), ['id' => '2']);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame('reply', $this->pdo->query('SELECT body FROM message WHERE id = 2')->fetchColumn());
    }

    public function test_customer_cannot_edit_another_customers_message_404(): void
    {
        $this->seed();
        $req = $this->request('PATCH', '/messages/3', 7, body: ['body' => 'hijack']);
        $res = (new UpdateAction($this->pdo))($req, new Response(), ['id' => '3']);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame('other', $this->pdo->query('SELECT body FROM message WHERE id = 3')->fetchColumn());
    }

    public function test_admin_can_edit_any_message(): void
    {
        $this->seed();
        $req = $this->request('PATCH', '/messages/3', 99, admin: true, body: ['body' => 'moderated']);
        $res = (new UpdateAction($this->pdo))($req, new Response(), ['id' => '3']);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('moderated', $this->pdo->query('SELECT body FROM message WHERE id = 3')->fetchColumn());
    }
}
