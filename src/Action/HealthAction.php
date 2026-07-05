<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * GET /healthz
 *
 * Liveness + dependency probe. No auth required so a monitor (uptime
 * check, GitHub Actions, the hosting control panel cron, etc.) can hit it freely.
 *
 * Returns 200 always — components report their own state in the body.
 * If something is "down" the consumer can page on the JSON, but the
 * endpoint itself doesn't 5xx because that turns into a noisy alarm
 * on every transient blip.
 */
final class HealthAction extends BaseAction
{
    /**
     * @param \Closure(): PDO $pdo Lazy provider — resolved inside the try/catch
     *        below so a DB/config failure reports `db: down` with HTTP 200
     *        instead of 5xx'ing during construction (the documented contract).
     * @param \Closure(): \Tds\CustomerApi\Service\AppSettings $settings Lazy
     *        provider for the runtime config store (Stripe key lives there now,
     *        DB-first with .env fallback). Resolved inside try/catch too.
     */
    public function __construct(
        private readonly \Closure $pdo,
        private readonly \Closure $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        return $this->json($response, 200, [
            'status' => 'ok',
            'db' => $this->checkDb(),
            'stripe' => $this->checkStripe(),
            'blob' => $this->checkBlobStorage(),
            'commit' => trim((string) (getenv('GIT_COMMIT') ?: 'unknown')),
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function checkDb(): string
    {
        // Two-stage probe: connectivity first, then schema presence. A bare
        // `SELECT 1` succeeds against an empty (un-migrated) database, so it
        // reports `ok` while every real query 500s (tds-customer-api#16).
        // Probing a real table distinguishes "reachable but never migrated"
        // from "reachable + ready".
        try {
            $pdo = ($this->pdo)();
            $pdo->query('SELECT 1');
        } catch (\Throwable) {
            return 'down';
        }

        try {
            $pdo->query('SELECT 1 FROM `customer` LIMIT 1');
            return 'ok';
        } catch (\Throwable $e) {
            return self::isMissingTable($e) ? 'no-schema' : 'down';
        }
    }

    /**
     * MySQL/MariaDB SQLSTATE 42S02 = "base table or view not found", i.e. the
     * DB is reachable but the migrations were never applied.
     */
    private static function isMissingTable(\Throwable $e): bool
    {
        return ($e instanceof \PDOException && $e->getCode() === '42S02')
            || str_contains($e->getMessage(), '42S02');
    }

    private function checkStripe(): string
    {
        // Configured when the DB store OR the .env has a key. Wrapped in
        // try/catch so a DB outage falls back to the env check and never 5xx's.
        try {
            $key = ($this->settings)()->get('STRIPE_SECRET_KEY');
        } catch (\Throwable) {
            $key = (string) (getenv('STRIPE_SECRET_KEY') ?: '');
        }
        return $key === '' ? 'missing' : 'configured';
    }

    private function checkBlobStorage(): string
    {
        $dir = (string) (getenv('DOCUMENT_ROOT_DIR') ?: '');
        if ($dir === '') return 'unconfigured';
        if (!is_dir($dir)) return 'missing';
        return is_writable($dir) ? 'writable' : 'unwritable';
    }
}
