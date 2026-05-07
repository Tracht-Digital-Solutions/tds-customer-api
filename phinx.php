<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/.env')) {
    require_once __DIR__ . '/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
}

$db = [
    'adapter' => 'mysql',
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'name' => $_ENV['DB_NAME'] ?? 'tds_customer',
    'user' => $_ENV['DB_USER'] ?? 'root',
    'pass' => $_ENV['DB_PASS'] ?? '',
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
            'name' => $_ENV['DB_NAME'] ?? 'tds_customer_local',
        ]),
    ],
];
