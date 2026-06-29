<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action\Account;

use Slim\Psr7\Response;
use Tds\CustomerApi\Action\Account\UpdateMeAction;
use Tds\CustomerApi\Tests\Support\DbTestCase;

/**
 * Integration test for PATCH /me. Covers the in-place name update, the
 * 1-200 char validation, the idempotent empty-body path, and that the
 * (possibly unchanged) profile is always returned so the editor never
 * shows stale data.
 */
final class UpdateMeActionTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerTable();
        $this->pdo->exec(
            "INSERT INTO customer (id, email, name) VALUES (7, 'erika@example.com', 'Erika')"
        );
    }

    public function test_updates_the_name_and_returns_the_row(): void
    {
        $req = $this->request('PATCH', '/me', 7, body: ['name' => 'Erika M.']);
        $res = (new UpdateMeAction($this->pdo))($req, new Response());

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Erika M.', $this->json($res)['customer']['name']);
        self::assertSame('Erika M.', $this->pdo->query('SELECT name FROM customer WHERE id = 7')->fetchColumn());
    }

    public function test_empty_body_is_idempotent_and_returns_current_profile(): void
    {
        $req = $this->request('PATCH', '/me', 7, body: []);
        $res = (new UpdateMeAction($this->pdo))($req, new Response());

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('Erika', $this->json($res)['customer']['name']);
    }

    public function test_blank_name_is_rejected_with_422(): void
    {
        $req = $this->request('PATCH', '/me', 7, body: ['name' => '   ']);
        $res = (new UpdateMeAction($this->pdo))($req, new Response());

        self::assertSame(422, $res->getStatusCode());
        self::assertArrayHasKey('name', $this->json($res)['issues']);
        // Unchanged in the DB.
        self::assertSame('Erika', $this->pdo->query('SELECT name FROM customer WHERE id = 7')->fetchColumn());
    }

    public function test_overlong_name_is_rejected_with_422(): void
    {
        $req = $this->request('PATCH', '/me', 7, body: ['name' => str_repeat('x', 201)]);
        $res = (new UpdateMeAction($this->pdo))($req, new Response());
        self::assertSame(422, $res->getStatusCode());
    }

    public function test_non_array_body_is_rejected_with_400(): void
    {
        $req = $this->request('PATCH', '/me', 7); // no parsed body
        $res = (new UpdateMeAction($this->pdo))($req, new Response());
        self::assertSame(400, $res->getStatusCode());
    }

    public function test_missing_customer_returns_404(): void
    {
        $req = $this->request('PATCH', '/me', 999, body: []);
        $res = (new UpdateMeAction($this->pdo))($req, new Response());
        self::assertSame(404, $res->getStatusCode());
    }
}
