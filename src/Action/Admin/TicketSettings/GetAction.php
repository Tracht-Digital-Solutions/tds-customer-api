<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TicketSettings;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketSettings;

/**
 * GET /admin/ticket-settings — the notification toggles, plus whether the mailer
 * is actually configured (so the admin UI can warn that toggles no-op when SMTP
 * is unconfigured).
 */
final class GetAction extends BaseAction
{
    public function __construct(
        private readonly TicketSettings $settings,
        private readonly TicketMailer $mailer,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        return $this->json($response, 200, [
            'settings' => $this->settings->all(),
            'mailerConfigured' => $this->mailer->isConfigured(),
        ]);
    }
}
