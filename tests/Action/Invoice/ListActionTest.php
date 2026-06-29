<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Action\Invoice;

use Slim\Psr7\Response;
use Tds\CustomerApi\Action\Invoice\ListAction;
use Tds\CustomerApi\Tests\Support\DbTestCase;

/**
 * Integration test for GET /invoices. Confirms the list is scoped to the
 * authenticated customer (a leak here exposes another customer's billing)
 * and ordered newest-due first.
 */
final class ListActionTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createInvoiceTable();
        $this->pdo->exec(<<<'SQL'
            INSERT INTO invoice (id, customer_id, amount_cents, currency, status, due_date, paid_at) VALUES
              (1, 7, 10000, 'EUR', 'open', '2026-03-01', NULL),
              (2, 7, 25000, 'EUR', 'paid', '2026-05-01', '2026-04-20 10:00:00'),
              (3, 8, 99900, 'EUR', 'open', '2026-06-01', NULL)
        SQL);
    }

    public function test_returns_only_the_callers_invoices(): void
    {
        $res = (new ListAction($this->pdo))($this->request('GET', '/invoices', 7), new Response());

        self::assertSame(200, $res->getStatusCode());
        $invoices = $this->json($res)['invoices'];
        self::assertCount(2, $invoices);
        $customerIds = array_map('intval', array_column($invoices, 'customer_id'));
        self::assertSame([7, 7], $customerIds);
        self::assertNotContains(8, $customerIds);
    }

    public function test_orders_by_due_date_descending(): void
    {
        $res = (new ListAction($this->pdo))($this->request('GET', '/invoices', 7), new Response());

        $invoices = $this->json($res)['invoices'];
        self::assertSame(2, (int) $invoices[0]['id']); // due 2026-05-01 first
        self::assertSame(1, (int) $invoices[1]['id']); // due 2026-03-01 second
    }

    public function test_empty_for_a_customer_with_no_invoices(): void
    {
        $res = (new ListAction($this->pdo))($this->request('GET', '/invoices', 999), new Response());

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $this->json($res)['invoices']);
    }
}
