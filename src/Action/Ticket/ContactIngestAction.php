<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Domain\Ticket;
use Tds\CustomerApi\Service\AppSettings;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;
use Tds\CustomerApi\Service\TicketStatusRepository;

/**
 * POST /tickets/contact
 *
 * Turns a contact-form submission (forwarded by tds-contact-api) into a support
 * ticket categorised as type='contact', source='contact'. NOT behind
 * JwksAuthMiddleware — like /tickets/ingest it is a trusted server-to-server
 * call authenticated with the shared `INGEST_TOKEN` secret (`?token=` or the
 * `X-Ingest-Token` header, compared constant-time), never a browser session.
 *
 * The submitter is usually NOT a customer, so the ticket carries their contact
 * details in from_name/from_email/from_company and has customer_id = NULL. When
 * the submitter's email does match a customer, the ticket is bound to that
 * customer instead (mirroring the IMAP ingest).
 */
final class ContactIngestAction extends BaseAction
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketStatusRepository $statuses,
        private readonly TicketMailer $mailer,
        private readonly TicketSettings $ticketSettings,
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, 400, ['error' => 'Invalid JSON body']);
        }

        // Validation mirrors tds-contact-api's ContactSchema port (name >= 2,
        // valid email, message >= 20). Character counts (mb_strlen), not bytes.
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $message = trim((string) ($body['message'] ?? ''));
        $companyRaw = isset($body['company']) ? trim((string) $body['company']) : '';
        $company = $companyRaw === '' ? null : mb_substr($companyRaw, 0, 200);

        if (mb_strlen($name) < 2
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($message) < 20
        ) {
            return $this->json($response, 422, ['error' => 'Invalid contact payload']);
        }

        $statusId = $this->statuses->defaultId();
        if ($statusId === null) {
            return $this->json($response, 503, ['error' => 'No default ticket status configured']);
        }

        // Bind to a customer when the sender is a known customer, else leave NULL
        // and keep their details in from_*.
        $customerId = $this->tickets->customerIdByEmail($email);

        $subject = mb_substr('Kontaktanfrage von ' . $name, 0, 200);
        $ticketId = $this->tickets->create([
            'customer_id' => $customerId,
            'project_id' => null,
            'status_id' => $statusId,
            'subject' => $subject,
            'description' => mb_substr($message, 0, 10000),
            'priority' => Ticket::DEFAULT_PRIORITY,
            'type' => 'contact',
            'created_by_type' => 'customer',
            'created_by_user_id' => null,
            'source' => 'contact',
            'email_message_id' => null,
            'from_name' => $name,
            'from_email' => $email,
            'from_company' => $company,
        ]);

        if ($this->ticketSettings->enabled('notify_admin_on_new')) {
            $this->mailer->notifyNewTicket($ticketId, $subject, $name);
        }

        return $this->json($response, 201, ['id' => $ticketId]);
    }
}
