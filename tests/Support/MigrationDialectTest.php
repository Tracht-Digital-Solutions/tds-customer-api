<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Static guard against migrations that pass on dev/CI MariaDB and then kill
 * the production installer on MySQL 8.
 *
 * Phinx defaults every `addColumn()` to NULLABLE. A table declared with
 * `'primary_key' => ['user_id']` whose `user_id` column doesn't say
 * `'null' => false` therefore emits a nullable PRIMARY KEY column. MariaDB
 * silently coerces that to NOT NULL; **MySQL 8 rejects it** with
 *
 *     SQLSTATE[42000] 1171 All parts of a PRIMARY KEY must be NOT NULL
 *
 * and the prod host is MySQL 8. This repo's own migrations are clean — they
 * carry the explicit NOT NULL and a comment saying why — but nothing kept them
 * that way. Two migrations in tds-auth-api drifted exactly this way and the
 * first symptom was `/install.php` dying mid-run on a fresh host, with every
 * migration after the failure left unapplied.
 *
 * This is the cheap half of the guard: no database, fails in the repo that
 * introduced the migration. The expensive half is the gateway's
 * `scripts/check-migrations-mysql8.php`, which applies every service's
 * migrations to a real MySQL 8 during the assemble and catches the dialect
 * traps no static check can see. Keep both — this one names the mistake, that
 * one proves the result.
 */
final class MigrationDialectTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__ . '/../../db/migrations';

    /**
     * Every column named in a `primary_key` table option must be declared
     * NOT NULL explicitly.
     */
    public function testPrimaryKeyColumnsAreExplicitlyNotNull(): void
    {
        $offenders = [];

        foreach ($this->migrationFiles() as $file) {
            $src = file_get_contents($file) ?: '';

            foreach ($this->tableDeclarations($src) as [$table, $options]) {
                foreach ($this->primaryKeyColumns($options) as $column) {
                    $columnOptions = $this->addColumnOptions($src, $column);

                    if ($columnOptions === null) {
                        $offenders[] = sprintf(
                            '%s: table "%s" declares PK column "%s" that is never added',
                            basename($file),
                            $table,
                            $column
                        );
                        continue;
                    }

                    if (!preg_match("#'null'\s*=>\s*false#", $columnOptions)) {
                        $offenders[] = sprintf(
                            '%s: table "%s" PK column "%s" is nullable — add \'null\' => false',
                            basename($file),
                            $table,
                            $column
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Nullable PRIMARY KEY column(s) — these install on MariaDB and fail on MySQL 8 (error 1171):\n  "
            . implode("\n  ", $offenders) . "\n"
        );
    }

    /** Sanity check: the scan must actually be looking at something. */
    public function testMigrationsAreFound(): void
    {
        $this->assertNotEmpty(
            $this->migrationFiles(),
            'No migrations scanned — the guard would pass vacuously.'
        );
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(self::MIGRATIONS_DIR . '/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Every `->table('name', [ ... ])` call, with its options array as raw
     * source. Bracket-matched rather than regex-terminated so a nested array
     * (`'primary_key' => ['a', 'b']`) can't truncate the options early.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function tableDeclarations(string $src): array
    {
        $found = [];
        $offset = 0;

        while (preg_match("#->table\(\s*'([^']+)'\s*,\s*\[#", $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openBracket = $m[0][1] + strlen($m[0][0]) - 1;
            $close = $this->matchingBracket($src, $openBracket);

            if ($close === null) {
                break;
            }

            $found[] = [$m[1][0], substr($src, $openBracket + 1, $close - $openBracket - 1)];
            $offset = $close;
        }

        return $found;
    }

    /** Index of the `]` closing the `[` at $open, or null if unbalanced. */
    private function matchingBracket(string $src, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '[') {
                $depth++;
            } elseif ($src[$i] === ']' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Column names listed in a `primary_key` option, which Phinx accepts
     * either as a bare string or as an array.
     *
     * @return list<string>
     */
    private function primaryKeyColumns(string $options): array
    {
        if (!preg_match("#'primary_key'\s*=>\s*(\[[^\]]*\]|'[^']*')#s", $options, $m)) {
            return [];
        }

        preg_match_all("#'([^']+)'#", $m[1], $names);

        return $names[1];
    }

    /**
     * The options array of `->addColumn('$column', 'type', [ ... ])` as raw
     * source, `''` when the call passes no options at all, or null when the
     * column is never added in this file.
     */
    private function addColumnOptions(string $src, string $column): ?string
    {
        $pattern = "#->addColumn\(\s*'" . preg_quote($column, '#') . "'\s*,\s*'[^']+'\s*(?:,\s*\[(.*?)\]\s*)?\)#s";

        if (!preg_match($pattern, $src, $m)) {
            return null;
        }

        return $m[1] ?? '';
    }
}
