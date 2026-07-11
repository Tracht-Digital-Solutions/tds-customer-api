<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

/**
 * Sends ticket notification emails over SMTP (via SmtpMailer). Every send is
 * best-effort: when SMTP is unconfigured the mailer no-ops, and any transport
 * error is swallowed — a failed notification must never break the ticket write
 * that triggered it. Whether a given event notifies at all is decided by the
 * caller via TicketSettings; this class only knows how to render + send.
 *
 * Customer-facing mails carry Reply-To = the IMAP-monitored inbox
 * (TICKET_INBOX_ADDRESS) and keep the "#<id>" subject marker, so a customer's
 * reply lands in the mailbox the IMAP ingester polls and threads onto the same
 * ticket. Without the Reply-To the reply would go to the noreply From and never
 * be seen.
 */
final class TicketMailer
{
    public function __construct(
        private readonly SmtpMailer $mailer,
        private readonly string $adminTo,
        private readonly string $inboxAddress,
        private readonly string $adminAppUrl,
        private readonly string $customerAppUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
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
            $this->customerReplyTo(),
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
            $this->customerReplyTo(),
        );
    }

    /**
     * @param array{replyTo?:string} $opts
     */
    private function send(string $to, string $subject, string $html, array $opts = []): void
    {
        try {
            $this->mailer->send($to, $subject, $html, $opts);
        } catch (\Throwable) {
            // Best-effort: a failed notification never breaks the ticket write.
        }
    }

    /** Reply-To for customer-facing mails: the IMAP-monitored inbox, if set. */
    private function customerReplyTo(): array
    {
        return $this->inboxAddress !== '' ? ['replyTo' => $this->inboxAddress] : [];
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
