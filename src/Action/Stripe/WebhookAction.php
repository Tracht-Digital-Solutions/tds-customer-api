<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Stripe;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Stripe\Webhook;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\AppSettings;

/**
 * POST /stripe/webhook
 *
 * Stripe-callable endpoint. NOT behind JwksAuthMiddleware — Stripe
 * authenticates via the Stripe-Signature header which is verified
 * here.
 *
 * Handles:
 *  - checkout.session.completed → mark invoice paid (idempotent)
 *
 * Issue #3 covers signature verification + idempotency hardening.
 */
final class WebhookAction extends BaseAction
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AppSettings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $secret = $this->settings->get('STRIPE_WEBHOOK_SECRET');
        if ($secret === '') {
            return $this->json($response, 503, ['error' => 'STRIPE_WEBHOOK_SECRET not configured']);
        }

        $payload = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Throwable $e) {
            return $this->json($response, 400, ['error' => 'Invalid signature']);
        }

        // `completed` is not `paid`: a delayed method (SEPA, bank transfer)
        // completes the session with payment_status `unpaid` and confirms
        // later with `checkout.session.async_payment_succeeded`.
        $paidEvent = $event->type === 'checkout.session.async_payment_succeeded'
            || ($event->type === 'checkout.session.completed'
                && (($event->data->object->payment_status ?? null) === 'paid'));

        if ($paidEvent) {
            $session = $event->data->object;
            $invoiceId = (int) ($session->metadata['invoice_id'] ?? 0);

            if ($invoiceId > 0) {
                $stmt = $this->pdo->prepare(
                    "UPDATE invoice SET status = 'paid', paid_at = NOW(), "
                    . "stripe_payment_intent_id = :pi WHERE id = :id AND status = 'open'"
                );
                $stmt->execute([
                    'id' => $invoiceId,
                    'pi' => $session->payment_intent ?? null,
                ]);
            }
        }

        return $this->json($response, 200, ['received' => true]);
    }
}
