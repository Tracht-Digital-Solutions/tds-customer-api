<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\AppSettings;
use Tds\CustomerApi\Service\ImapTicketIngest;

/**
 * POST /tickets/ingest
 *
 * Runs one IMAP polling pass. NOT behind JwksAuthMiddleware — the prod host has
 * no cron/CLI, so an external scheduler (GitHub Actions / a Plesk scheduled URL
 * fetch) drives this; it authenticates with the shared `INGEST_TOKEN` secret
 * passed as `?token=` or the `X-Ingest-Token` header, compared constant-time.
 * Mirrors the /stripe/webhook "secret is the auth" pattern.
 */
final class IngestAction extends BaseAction
{
    public function __construct(
        private readonly ImapTicketIngest $ingest,
        private readonly AppSettings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $expected = $this->settings->get('INGEST_TOKEN');
        if ($expected === '') {
            return $this->json($response, 503, ['error' => 'INGEST_TOKEN not configured']);
        }

        $params = $request->getQueryParams();
        $provided = (string) ($params['token'] ?? $request->getHeaderLine('X-Ingest-Token'));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->json($response, 401, ['error' => 'Invalid ingest token']);
        }

        $stats = $this->ingest->poll();
        return $this->json($response, 200, $stats);
    }
}
