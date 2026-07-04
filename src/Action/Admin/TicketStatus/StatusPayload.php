<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TicketStatus;

use Tds\CustomerApi\Service\TicketStatusRepository;

/**
 * Shared validation/normalisation for the status create + update payloads.
 */
final class StatusPayload
{
    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>|string normalised data, or an error message string
     */
    public static function parse(array $body): array|string
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            return 'name must be 1-80 chars';
        }

        $color = (string) ($body['color'] ?? 'neutral');
        if (!in_array($color, TicketStatusRepository::COLORS, true)) {
            return 'color must be one of: ' . implode(', ', TicketStatusRepository::COLORS);
        }

        $sortOrder = isset($body['sortOrder']) ? (int) $body['sortOrder'] : 0;

        return [
            'name' => $name,
            'color' => $color,
            'sort_order' => $sortOrder,
            'visible_to_customer' => (bool) ($body['visibleToCustomer'] ?? true),
            'is_terminal' => (bool) ($body['isTerminal'] ?? false),
            'is_default' => (bool) ($body['isDefault'] ?? false),
        ];
    }
}
