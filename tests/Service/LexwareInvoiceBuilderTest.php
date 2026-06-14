<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Service\LexwareInvoiceBuilder;

final class LexwareInvoiceBuilderTest extends TestCase
{
    private function build(array $entries): array
    {
        return (new LexwareInvoiceBuilder())->build(
            entries: $entries,
            customerName: 'ACME GmbH',
            projectTitle: 'Website Relaunch',
            hourlyRateNet: 95.0,
            taxRatePercentage: 19.0,
            voucherDate: new \DateTimeImmutable('2026-06-14T10:00:00+02:00'),
        );
    }

    public function test_groups_minutes_by_milestone_into_service_line_items(): void
    {
        $res = $this->build([
            ['duration_minutes' => 90, 'milestone_title' => 'Konzept'],
            ['duration_minutes' => 30, 'milestone_title' => 'Konzept'],
            ['duration_minutes' => 120, 'milestone_title' => 'Umsetzung'],
        ]);

        self::assertSame(240, $res['totalMinutes']);
        self::assertSame(2, $res['lineItemCount']);

        $items = $res['payload']['lineItems'];
        self::assertSame('Konzept', $items[0]['description']);
        self::assertSame(2.0, $items[0]['quantity']); // 120 min
        self::assertSame('service', $items[0]['type']);
        self::assertSame('Stunde', $items[0]['unitName']);
        self::assertSame(95.0, $items[0]['unitPrice']['netAmount']);
        self::assertSame(19.0, $items[0]['unitPrice']['taxRatePercentage']);
        self::assertSame('EUR', $items[0]['unitPrice']['currency']);
        self::assertSame(2.0, $items[1]['quantity']); // Umsetzung 120 min
    }

    public function test_entries_without_milestone_fall_under_project_title(): void
    {
        $res = $this->build([
            ['duration_minutes' => 60, 'milestone_title' => null],
        ]);

        self::assertSame(1, $res['lineItemCount']);
        self::assertSame('Website Relaunch', $res['payload']['lineItems'][0]['description']);
        self::assertSame(1.0, $res['payload']['lineItems'][0]['quantity']);
    }

    public function test_zero_minute_entries_are_skipped(): void
    {
        $res = $this->build([
            ['duration_minutes' => 0, 'milestone_title' => 'Leer'],
            ['duration_minutes' => 45, 'milestone_title' => 'Arbeit'],
        ]);

        self::assertSame(45, $res['totalMinutes']);
        self::assertSame(1, $res['lineItemCount']);
        self::assertSame(0.75, $res['payload']['lineItems'][0]['quantity']);
    }

    public function test_empty_entries_yield_no_line_items(): void
    {
        $res = $this->build([]);
        self::assertSame(0, $res['lineItemCount']);
        self::assertSame(0, $res['totalMinutes']);
        self::assertSame([], $res['payload']['lineItems']);
    }

    public function test_payload_carries_net_tax_conditions_and_address(): void
    {
        $payload = $this->build([['duration_minutes' => 60, 'milestone_title' => 'X']])['payload'];

        self::assertSame('net', $payload['taxConditions']['taxType']);
        self::assertSame('EUR', $payload['totalPrice']['currency']);
        self::assertSame('ACME GmbH', $payload['address']['name']);
        self::assertSame('DE', $payload['address']['countryCode']);
        self::assertStringStartsWith('2026-06-14T10:00:00.000', $payload['voucherDate']);
    }
}
