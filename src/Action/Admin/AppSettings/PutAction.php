<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin\AppSettings;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\AppSettings;

/**
 * PUT /admin/settings — update runtime service config.
 * Body: a flat map of { SETTING_KEY: value }. Unknown keys are ignored; a
 * blank secret value keeps the existing one (see AppSettings::put). Returns
 * the masked state so the UI can re-render without a second GET.
 */
final class PutAction extends BaseAction
{
    public function __construct(private readonly AppSettings $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        $values = [];
        foreach (AppSettings::keys() as $key) {
            if (array_key_exists($key, $body)) {
                $values[$key] = (string) $body[$key];
            }
        }
        $this->settings->put($values);

        return $this->json($response, 200, $this->settings->publicState());
    }
}
