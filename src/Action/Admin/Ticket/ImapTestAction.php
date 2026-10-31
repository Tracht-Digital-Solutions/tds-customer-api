<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\ImapTicketIngest;

/**
 * GET /admin/tickets/imap-test
 *
 * Attempts an IMAP connect + folder open and reports the result, for the
 * "Verbindung testen" button in tds-admin. Always 200 — the outcome is in the
 * body ({ok:true} or {ok:false,error}) so the UI can show the message.
 */
final class ImapTestAction extends BaseAction
{
    public function __construct(private readonly ImapTicketIngest $ingest)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        return $this->json($response, 200, $this->ingest->testConnection());
    }
}
