<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\ImapTicketIngest;

/**
 * POST /admin/tickets/ingest
 *
 * Admin-triggered ("Jetzt abrufen" button) IMAP poll. Behind the admin JWT.
 * Same one-pass poll() as the scheduled public endpoint, for on-demand runs.
 */
final class IngestAction extends BaseAction
{
    public function __construct(private readonly ImapTicketIngest $ingest)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if (!$this->ingest->isConfigured()) {
            return $this->json($response, 503, ['error' => 'IMAP not configured']);
        }
        $stats = $this->ingest->poll();
        return $this->json($response, 200, $stats);
    }
}
