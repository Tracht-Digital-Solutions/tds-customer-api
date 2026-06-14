<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

/**
 * Turns a project's time entries into a Lexware invoice payload.
 *
 * Entries are grouped by milestone (entries without one fall under the
 * project title) so the invoice reads as one service line per milestone,
 * quantity = hours, priced at the given hourly net rate. Pure + stateless
 * so the aggregation is unit-testable without the HTTP client.
 */
final class LexwareInvoiceBuilder
{
    /**
     * @param array<int,array<string,mixed>> $entries rows with
     *        duration_minutes + milestone_title (nullable)
     * @return array{payload:array<string,mixed>,totalMinutes:int,lineItemCount:int}
     */
    public function build(
        array $entries,
        string $customerName,
        string $projectTitle,
        float $hourlyRateNet,
        float $taxRatePercentage,
        \DateTimeInterface $voucherDate,
        string $currency = 'EUR',
    ): array {
        // Sum minutes per milestone label, preserving first-seen order.
        $minutesByLabel = [];
        $totalMinutes = 0;
        foreach ($entries as $e) {
            $minutes = (int) ($e['duration_minutes'] ?? 0);
            if ($minutes <= 0) {
                continue;
            }
            $label = isset($e['milestone_title']) && is_string($e['milestone_title']) && $e['milestone_title'] !== ''
                ? $e['milestone_title']
                : $projectTitle;
            $minutesByLabel[$label] = ($minutesByLabel[$label] ?? 0) + $minutes;
            $totalMinutes += $minutes;
        }

        $rate = round($hourlyRateNet, 2);
        $lineItems = [];
        foreach ($minutesByLabel as $label => $minutes) {
            $hours = round($minutes / 60, 2);
            $lineItems[] = [
                'type' => 'service',
                'name' => $projectTitle,
                'description' => $label,
                'quantity' => $hours,
                'unitName' => 'Stunde',
                'unitPrice' => [
                    'currency' => $currency,
                    'netAmount' => $rate,
                    'taxRatePercentage' => $taxRatePercentage,
                ],
            ];
        }

        $payload = [
            'voucherDate' => $voucherDate->format('Y-m-d\TH:i:s.000P'),
            'address' => [
                'name' => $customerName,
                'countryCode' => 'DE',
            ],
            'lineItems' => $lineItems,
            'totalPrice' => ['currency' => $currency],
            'taxConditions' => ['taxType' => 'net'],
            'shippingConditions' => [
                'shippingType' => 'service',
                'shippingDate' => $voucherDate->format('Y-m-d\TH:i:s.000P'),
            ],
            'title' => 'Rechnung',
            'introduction' => sprintf(
                'Erfasste Leistungen für %s (%s Stunden).',
                $projectTitle,
                number_format($totalMinutes / 60, 2, ',', '.'),
            ),
        ];

        return [
            'payload' => $payload,
            'totalMinutes' => $totalMinutes,
            'lineItemCount' => count($lineItems),
        ];
    }
}
