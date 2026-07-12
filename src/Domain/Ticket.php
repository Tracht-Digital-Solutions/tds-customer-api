<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Domain;

/**
 * Ticket enum vocabularies — hand-duplicated from tds-shared's
 * TICKET_PRIORITIES / TICKET_TYPES (same Zod↔PHP duplication convention the
 * other resources follow). Status is NOT here — it is the runtime-configurable
 * ticket_status registry.
 */
final class Ticket
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    // 'contact' categorises contact-form tickets (source='contact') apart from
    // the support types; it is set by ContactIngestAction, never the default.
    public const TYPES = ['question', 'bug', 'feature', 'other', 'contact'];

    public const DEFAULT_PRIORITY = 'normal';
    public const DEFAULT_TYPE = 'question';

    public static function isValidPriority(string $value): bool
    {
        return in_array($value, self::PRIORITIES, true);
    }

    public static function isValidType(string $value): bool
    {
        return in_array($value, self::TYPES, true);
    }
}
