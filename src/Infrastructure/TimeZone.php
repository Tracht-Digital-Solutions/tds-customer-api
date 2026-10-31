<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * The one wall-clock zone this service reads and writes: Europe/Berlin.
 *
 * Production already runs there on both sides — PHP's `date.timezone` and the
 * MySQL session default — so every `NOW()` / `CURRENT_TIMESTAMP` column holds
 * Berlin wall-clock time, and `date()` / `strtotime()` agree with it. Nothing
 * in the code said so. CLI PHP on a dev machine and the CI database containers
 * default to UTC, so a comparison that holds in production is two hours off
 * everywhere else, and a bug in either direction stays invisible until it
 * ships. Pinning both sides at boot makes the production behaviour the only one.
 *
 * This pins a DEFAULT and converts nothing. A column written with `gmdate()` or
 * `UTC_TIMESTAMP()` stays UTC and needs a reader that names the zone (AGENTS.md,
 * "Time zones"). Moving everything to UTC means converting existing rows
 * DST-correctly across every service's tables — a separate decision.
 */
final class TimeZone
{
    public const NAME = 'Europe/Berlin';

    /** PHP's default zone, for `date()`, `strtotime()` and `new DateTime()`. */
    public static function pinPhp(): void
    {
        date_default_timezone_set(self::NAME);
    }

    /**
     * The connection's session zone, for `NOW()`, `CURRENT_TIMESTAMP` and the
     * conversion of TIMESTAMP columns.
     *
     * A named zone needs MySQL's time-zone tables, and the official MySQL and
     * MariaDB images ship them EMPTY, so the named SET can fail. A session that
     * then already runs at Berlin's current offset is left alone: that is a
     * host whose `SYSTEM` zone is Berlin, and its DST rules read old TIMESTAMP
     * values correctly where a fixed offset would be an hour off for half the
     * year. Only a session at another offset (UTC in a container) is set to
     * Berlin's current offset, which is exact for a request-long connection.
     */
    public static function pinSession(PDO $pdo, ?DateTimeImmutable $now = null): void
    {
        try {
            $pdo->exec("SET time_zone = '" . self::NAME . "'");
            return;
        } catch (PDOException) {
            // No time-zone tables on this server: compare offsets instead.
        }

        $statement = $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
        $current = $statement === false ? false : $statement->fetchColumn();
        if ($current !== false && (int) $current === self::offsetSeconds($now)) {
            return;
        }
        $pdo->exec("SET time_zone = '" . self::offset($now) . "'");
    }

    /** Berlin's UTC offset at `$now` (default: the current time), as `+HH:MM`. */
    public static function offset(?DateTimeImmutable $now = null): string
    {
        return self::berlin($now)->format('P');
    }

    /** Berlin's UTC offset at `$now` (default: the current time), in seconds. */
    public static function offsetSeconds(?DateTimeImmutable $now = null): int
    {
        return self::berlin($now)->getOffset();
    }

    private static function berlin(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::NAME));
    }
}
