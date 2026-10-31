<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\AppSettings;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\AppSettings;

/**
 * GET /admin/settings — masked, section-grouped state of the runtime service
 * config (Stripe / ticket mailer / Lexware). Secrets are never returned raw,
 * only configured/last4/source. Consumed by the Einstellungen page and the
 * Einrichtungsassistent in tds-admin.
 */
final class GetAction extends BaseAction
{
    public function __construct(private readonly AppSettings $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        return $this->json($response, 200, $this->settings->publicState());
    }
}
