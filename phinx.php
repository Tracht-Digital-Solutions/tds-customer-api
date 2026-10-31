<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/.env')) {
    require_once __DIR__ . '/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
}

/**
 * Read a DB setting from the .env-populated $_ENV, falling back to the real
 * process environment.
 *
 * The getenv() fallback exists so the migration set can be run with nothing
 * but exported variables — which is what the "migrate against MySQL 8" CI step
 * does. PHP's default `variables_order` (GPCS) leaves $_ENV unpopulated from
 * the real environment, so $_ENV alone would silently fall through to the
 * defaults below and migrate the wrong database.
 *
 * Note the explicit `=== false` checks: writing `$_ENV[$k] ?? getenv($k) ?: $default`
 * binds `??` tighter than `?:` and would clobber a legitimately empty DB_PASS
 * with the default. That trap has bitten every API repo in this platform.
 */
$env = static function (string $key, string $default): string {
    $value = $_ENV[$key] ?? false;

    if ($value === false) {
        $value = getenv($key);
    }

    return $value === false ? $default : (string) $value;
};

$db = [
    'adapter' => 'mysql',
    'host' => $env('DB_HOST', '127.0.0.1'),
    'port' => $env('DB_PORT', '3306'),
    'name' => $env('DB_NAME', 'tds_customer'),
    'user' => $env('DB_USER', 'root'),
    'pass' => $env('DB_PASS', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

return [
    'paths' => ['migrations' => 'db/migrations'],
    'environments' => [
        'default_migration_table' => 'phinx_migration',
        'default_environment' => 'production',
        'production' => $db,
        'local' => array_merge($db, [
            'host' => '127.0.0.1',
            'name' => $env('DB_NAME', 'tds_customer_local'),
        ]),
    ],
];
