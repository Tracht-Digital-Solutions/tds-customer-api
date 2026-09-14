<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Infrastructure;

use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Infrastructure\TimeZone;

/**
 * The zone pin. Production runs PHP and MySQL in Europe/Berlin; CLI PHP and the
 * CI database containers default to UTC, which is how a comparison between
 * `date()` and `NOW()` can hold in production and fail everywhere else — or the
 * reverse. The mocked cases need no database; the last one runs against a real
 * server when `TDS_TEST_DB_DSN` is set.
 */
final class TimeZoneTest extends TestCase
{
    private string $phpZone;

    protected function setUp(): void
    {
        $this->phpZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->phpZone);
    }

    public function test_pins_php_to_berlin(): void
    {
        date_default_timezone_set('UTC');

        TimeZone::pinPhp();

        self::assertSame('Europe/Berlin', date_default_timezone_get());
    }

    public function test_offset_follows_daylight_saving_time(): void
    {
        self::assertSame('+02:00', TimeZone::offset(new DateTimeImmutable('2026-07-01 12:00:00 UTC')));
        self::assertSame('+01:00', TimeZone::offset(new DateTimeImmutable('2026-01-15 12:00:00 UTC')));
        // The switch itself: summer time ends on 2026-10-25 at 01:00 UTC.
        self::assertSame('+02:00', TimeZone::offset(new DateTimeImmutable('2026-10-25 00:59:59 UTC')));
        self::assertSame('+01:00', TimeZone::offset(new DateTimeImmutable('2026-10-25 01:00:00 UTC')));
        self::assertSame(7200, TimeZone::offsetSeconds(new DateTimeImmutable('2026-07-01 12:00:00 UTC')));
    }

    public function test_uses_the_named_zone_when_the_server_knows_it(): void
    {
        $executed = [];
        $pdo = $this->pdo($executed, namedZoneWorks: true, sessionOffset: 0);

        TimeZone::pinSession($pdo);

        self::assertSame(["SET time_zone = 'Europe/Berlin'"], $executed);
    }

    public function test_falls_back_to_the_current_offset_on_a_utc_container(): void
    {
        $executed = [];
        $pdo = $this->pdo($executed, namedZoneWorks: false, sessionOffset: 0);

        TimeZone::pinSession($pdo, new DateTimeImmutable('2026-07-01 12:00:00 UTC'));

        self::assertSame(["SET time_zone = 'Europe/Berlin'", "SET time_zone = '+02:00'"], $executed);
    }

    public function test_leaves_a_session_alone_that_already_runs_at_berlin_time(): void
    {
        // A host whose SYSTEM zone is Berlin. Its DST rules read a winter
        // TIMESTAMP correctly in summer; a fixed '+02:00' would not.
        $executed = [];
        $pdo = $this->pdo($executed, namedZoneWorks: false, sessionOffset: 3600);

        TimeZone::pinSession($pdo, new DateTimeImmutable('2026-01-15 12:00:00 UTC'));

        self::assertSame(["SET time_zone = 'Europe/Berlin'"], $executed);
    }

    public function test_a_real_session_runs_at_berlin_time(): void
    {
        $dsn = getenv('TDS_TEST_DB_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set TDS_TEST_DB_DSN to run the session zone test.');
        }
        $pdo = new PDO($dsn, getenv('TDS_TEST_DB_USER') ?: null, getenv('TDS_TEST_DB_PASS') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        TimeZone::pinSession($pdo);

        $seconds = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
        self::assertSame(TimeZone::offsetSeconds(), $seconds);
    }

    /** @param list<string> $executed */
    private function pdo(array &$executed, bool $namedZoneWorks, int $sessionOffset): PDO
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn((string) $sessionOffset);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturnCallback(
            static function (string $sql) use (&$executed, $namedZoneWorks): int {
                $executed[] = $sql;
                if (!$namedZoneWorks && str_contains($sql, 'Europe/Berlin')) {
                    throw new PDOException("SQLSTATE[HY000]: General error: 1298 Unknown or incorrect time zone: 'Europe/Berlin'");
                }
                return 0;
            },
        );
        $pdo->method('query')->willReturn($statement);

        return $pdo;
    }
}
