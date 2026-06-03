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
    public function __construct(private readonly PDO $pdo)
    {
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
        try {
            $this->pdo->query('SELECT 1');
            return 'ok';
        } catch (\Throwable) {
            return 'down';
        }
    }

    private function checkStripe(): string
    {
        $key = (string) (getenv('STRIPE_SECRET_KEY') ?: '');
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
