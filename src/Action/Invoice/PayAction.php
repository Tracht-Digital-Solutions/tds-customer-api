<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Invoice;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\AppSettings;

/**
 * POST /invoices/{id}/pay
 *
 * Creates a Stripe Checkout session for an open invoice and returns
 * the redirect URL. The frontend then sends the user to that URL.
 *
 * Stripe webhook (POST /stripe/webhook) flips the invoice to paid
 * after `checkout.session.completed`.
 */
final class PayAction extends BaseAction
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AppSettings $settings,
    ) {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $invoiceId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT id, amount_cents, currency, status FROM invoice "
            . "WHERE id = :id AND customer_id = :cid LIMIT 1"
        );
        $stmt->execute(['id' => $invoiceId, 'cid' => $customerId]);
        $invoice = $stmt->fetch();

        if ($invoice === false) {
            return $this->json($response, 404, ['error' => 'Invoice not found']);
        }
        if ($invoice['status'] !== 'open') {
            return $this->json($response, 409, ['error' => 'Invoice not payable in current status']);
        }

        Stripe::setApiKey($this->settings->get('STRIPE_SECRET_KEY'));
        $returnUrl = $this->settings->get('STRIPE_RETURN_URL');

        $session = StripeSession::create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower((string) $invoice['currency']),
                    'unit_amount' => (int) $invoice['amount_cents'],
                    'product_data' => ['name' => "Invoice #{$invoice['id']}"],
                ],
            ]],
            'success_url' => $returnUrl . '?paid=' . $invoiceId,
            'cancel_url' => $returnUrl . '?canceled=' . $invoiceId,
            'metadata' => [
                'invoice_id' => (string) $invoiceId,
                'customer_id' => (string) $customerId,
            ],
        ]);

        return $this->json($response, 200, ['url' => $session->url]);
    }
}
