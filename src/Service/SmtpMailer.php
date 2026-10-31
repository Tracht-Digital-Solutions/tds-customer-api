<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Thin SMTP transport built directly from Symfony's EsmtpTransport (no DSN, so a
 * password with URL-special characters never has to be encoded). Sends over
 * stream sockets, which works in-process under the gateway's PHP-FPM app — the
 * prod Plesk host disables proc_open, so a socket transport is the only viable
 * option (the old Resend HTTP path is retired).
 *
 * Security modes: 'ssl' → implicit TLS (port 465); 'tls'/'none' → plain connect
 * that auto-upgrades to STARTTLS when the server advertises it (587 for real
 * providers, plain for a local MailHog/Mailpit catch-all). Unconfigured (no host
 * or no from) ⇒ no-op, so ticket writes never break on an unconfigured mailer.
 */
final class SmtpMailer
{
    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $security, // 'tls' | 'ssl' | 'none'
        private readonly string $from,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->from !== '';
    }

    /**
     * @param array{replyTo?:string,headers?:array<string,string>} $opts
     */
    public function send(string $to, string $subject, string $html, array $opts = []): void
    {
        if (!$this->isConfigured() || $to === '') {
            return;
        }

        $implicitTls = $this->security === 'ssl';
        $port = (int) ($this->port !== '' ? $this->port : ($implicitTls ? 465 : 587));
        $transport = new EsmtpTransport($this->host, $port, $implicitTls);
        if ($this->user !== '') {
            $transport->setUsername($this->user);
            $transport->setPassword($this->pass);
        }

        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject($subject)
            ->html($html);

        if (!empty($opts['replyTo'])) {
            $email->replyTo($opts['replyTo']);
        }
        foreach ($opts['headers'] ?? [] as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        (new Mailer($transport))->send($email);
    }
}
