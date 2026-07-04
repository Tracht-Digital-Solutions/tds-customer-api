<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use GuzzleHttp\Client;

/**
 * Sends ticket notification emails via the Resend HTTP API (same host-friendly
 * approach as tds-contact-api's ResendEmailService). Every send is best-effort:
 * when RESEND_API_KEY is unset the mailer no-ops, and any transport error is
 * swallowed — a failed notification must never break the ticket write that
 * triggered it. Whether a given event notifies at all is decided by the caller
 * via TicketSettings; this class only knows how to render + send.
 */
final class TicketMailer
{
    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $from,
        private readonly string $adminTo,
        private readonly string $adminAppUrl,
        private readonly string $customerAppUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /** New ticket → notify the admin/support inbox. */
    public function notifyNewTicket(int $ticketId, string $subject, string $customerName): void
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES);
        $safeCustomer = htmlspecialchars($customerName, ENT_QUOTES);
        $this->send(
            $this->adminTo,
            sprintf('Neues Ticket #%d: %s', $ticketId, $subject),
            $this->layout(
                'Neues Support-Ticket',
                "<p><strong>{$safeCustomer}</strong> hat ein neues Ticket erstellt:</p>"
                . "<p style=\"font-size:16px;font-weight:600;\">#{$ticketId} · {$safeSubject}</p>",
                'Ticket öffnen',
                rtrim($this->adminAppUrl, '/') . '/tickets/' . $ticketId,
            ),
        );
    }

    /** Visible status change → notify the customer. */
    public function notifyCustomerStatusChange(string $email, int $ticketId, string $subject, string $statusName): void
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES);
        $safeStatus = htmlspecialchars($statusName, ENT_QUOTES);
        $this->send(
            $email,
            sprintf('Ticket #%d: Status aktualisiert', $ticketId),
            $this->layout(
                'Status aktualisiert',
                "<p>Der Status Ihres Tickets <strong>#{$ticketId} · {$safeSubject}</strong> wurde geändert zu:</p>"
                . "<p style=\"font-size:16px;font-weight:600;\">{$safeStatus}</p>",
                'Ticket ansehen',
                rtrim($this->customerAppUrl, "/") . "/tickets/" . $ticketId,
            ),
        );
    }

    /** New admin reply → notify the customer. */
    public function notifyCustomerReply(string $email, int $ticketId, string $subject): void
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES);
        $this->send(
            $email,
            sprintf('Ticket #%d: Neue Antwort', $ticketId),
            $this->layout(
                'Neue Antwort',
                "<p>Es gibt eine neue Antwort auf Ihr Ticket <strong>#{$ticketId} · {$safeSubject}</strong>.</p>",
                'Antwort ansehen',
                rtrim($this->customerAppUrl, "/") . "/tickets/" . $ticketId,
            ),
        );
    }

    private function send(string $to, string $subject, string $html): void
    {
        if ($this->apiKey === '' || $to === '') {
            return;
        }
        try {
            $this->http->post('https://api.resend.com/emails', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'from' => $this->from,
                    'to' => [$to],
                    'subject' => $subject,
                    'html' => $html,
                ],
                'timeout' => 8,
            ]);
        } catch (\Throwable) {
            // Best-effort: a failed notification never breaks the ticket write.
        }
    }

    private function layout(string $heading, string $bodyHtml, string $ctaLabel, string $ctaUrl): string
    {
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES);
        $safeCta = htmlspecialchars($ctaLabel, ENT_QUOTES);
        $safeUrl = htmlspecialchars($ctaUrl, ENT_QUOTES);
        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>{$safeHeading}</title></head>
<body style="margin:0;padding:0;background:#fafaf7;font-family:system-ui,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 20px;"><tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e8e6df;border-radius:4px;overflow:hidden;">
      <tr><td style="background:#050f68;padding:32px 40px;">
        <p style="margin:0;font-size:20px;font-weight:600;color:#ffffff;">Tracht Digital Solutions</p>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(255,255,255,0.6);">{$safeHeading}</p>
      </td></tr>
      <tr><td style="padding:40px;color:#0b0a07;font-size:14px;line-height:1.7;">
        {$bodyHtml}
        <p style="margin:32px 0 0;"><a href="{$safeUrl}" style="display:inline-block;background:#050f68;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:4px;font-weight:600;">{$safeCta}</a></p>
      </td></tr>
    </table>
  </td></tr></table>
</body>
</html>
HTML;
    }
}
