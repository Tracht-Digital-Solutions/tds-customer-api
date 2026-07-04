<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\TicketSettings;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\TicketSettings;

/**
 * PUT /admin/ticket-settings — update the notification toggles.
 * Body: { notify_admin_on_new?, notify_customer_on_status?, notify_customer_on_reply? }
 * (booleans). Unknown keys are ignored.
 */
final class PutAction extends BaseAction
{
    public function __construct(private readonly TicketSettings $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $values = [];
        foreach (TicketSettings::KEYS as $key) {
            if (array_key_exists($key, $body)) {
                $values[$key] = (bool) $body[$key];
            }
        }
        $this->settings->put($values);

        return $this->json($response, 200, ['settings' => $this->settings->all()]);
    }
}
