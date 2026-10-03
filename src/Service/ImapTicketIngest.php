<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use PDO;
use Tds\CustomerApi\Domain\Ticket;
use Webklex\PHPIMAP\ClientManager;

/**
 * Turns inbound IMAP mail into support tickets. One poll() pass connects to the
 * configured mailbox, fetches UNSEEN messages and, per message:
 *
 *  - resolves the sender against customer.email (UNIQUE) — unknown senders are
 *    skipped/logged, never turned into tickets (anti-spam);
 *  - dedupes on the mail's Message-ID (a re-delivered message is skipped even if
 *    a prior pass failed to mark it \Seen);
 *  - threads onto an existing ticket when the subject carries a "#<id>" marker or
 *    the In-Reply-To/References headers match a stored Message-ID *and* the
 *    ticket belongs to the sender — otherwise opens a new `source=email` ticket;
 *  - stores allowed attachments and marks the message \Seen.
 *
 * The webklex/php-imap client talks IMAP over stream sockets (no ext-imap, no
 * proc_open) so it can run in-process under the gateway. There is no long-running
 * worker on the prod host: poll() is driven by an external scheduler hitting the
 * secret ingest endpoint (and the manual "Jetzt abrufen" admin button).
 *
 * All message parsing lives in the pure static helpers below so it is unit-
 * testable without a live mailbox; handle() does the DB work off a normalised
 * array and is likewise testable with a fake message.
 */
final class ImapTicketIngest
{
    private const MAX_BODY = 10000;
    private const MAX_PER_POLL = 50;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TicketRepository $tickets,
        private readonly TicketStatusRepository $statuses,
        private readonly AttachmentStorage $attachments,
        private readonly TicketMailer $mailer,
        private readonly TicketSettings $settings,
        private readonly string $host,
        private readonly string $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $security, // 'ssl' | 'tls' | 'none'
        private readonly string $folder,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->user !== '';
    }

    /** @return array{ok:bool,error?:string} */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'IMAP ist nicht konfiguriert.'];
        }
        try {
            $client = $this->connect();
            $client->getFolder($this->folder !== '' ? $this->folder : 'INBOX');
            $client->disconnect();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run one polling pass.
     *
     * @return array{processed:int,created:int,appended:int,skipped:int}
     */
    public function poll(): array
    {
        $stats = ['processed' => 0, 'created' => 0, 'appended' => 0, 'skipped' => 0];
        if (!$this->isConfigured()) {
            return $stats;
        }

        $client = $this->connect();
        try {
            $folder = $client->getFolder($this->folder !== '' ? $this->folder : 'INBOX');
            $messages = $folder->messages()->unseen()->limit(self::MAX_PER_POLL)->get();

            foreach ($messages as $message) {
                $stats['processed']++;
                try {
                    $outcome = $this->handle($this->normalize($message));
                    if (isset($stats[$outcome])) {
                        $stats[$outcome]++;
                    }
                    // Mark \Seen only after a clean pass so a transient failure
                    // (below, in the catch) leaves the message for the next poll.
                    $message->setFlag('Seen');
                } catch (\Throwable $e) {
                    error_log('[ingest] message failed: ' . $e->getMessage());
                }
            }
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // ignore
            }
        }

        error_log(sprintf(
            '[ingest] processed=%d created=%d appended=%d skipped=%d',
            $stats['processed'],
            $stats['created'],
            $stats['appended'],
            $stats['skipped'],
        ));
        return $stats;
    }

    /**
     * Persist one normalised message. Returns 'created' | 'appended' | 'skipped'.
     *
     * @param array{message_id:string,from:string,subject:string,references:list<string>,body:string,attachments:list<array{filename:string,bytes:string,mime:string}>} $mail
     */
    public function handle(array $mail): string
    {
        $from = $mail['from'];
        if ($from === '') {
            return 'skipped';
        }

        $customerId = $this->tickets->customerIdByEmail($from);

        $messageId = $mail['message_id'];
        if ($messageId !== '' && $this->tickets->emailMessageIdSeen($messageId)) {
            return 'skipped';
        }

        $body = $mail['body'] !== '' ? $mail['body'] : '(Kein Textinhalt in der E-Mail.)';

        // Reply onto an existing ticket the sender owns? A known customer can
        // append to any of their tickets; an unknown sender only to their own
        // contact-form ticket (matched by from_email) — that lets a contact
        // reply thread back even though they have no customer account.
        $ticketId = self::parseTicketIdFromSubject($mail['subject']);
        $existing = $customerId !== null
            ? $this->tickets->findForEmailReply($ticketId, $mail['references'], $customerId)
            : $this->tickets->findContactTicketForReply($ticketId, $mail['references'], $from);
        if ($existing !== null) {
            $commentId = $this->tickets->addComment([
                'ticket_id' => $existing,
                'author_type' => 'customer',
                'author_user_id' => null,
                'body' => $body,
                'is_internal' => false,
                'email_message_id' => $messageId,
            ]);
            $this->tickets->clearCustomerAction($existing);
            // Attachments are bucketed by customer id on disk; a null-customer
            // contact ticket uses the shared 0 bucket.
            $this->storeAttachments($customerId ?? 0, $existing, $commentId, $mail['attachments']);
            return 'appended';
        }

        // A brand-new mail from an unknown sender is never turned into a ticket
        // (anti-spam) — only replies to an existing contact ticket thread above.
        if ($customerId === null) {
            error_log('[ingest] skip: unknown sender ' . $from);
            return 'skipped';
        }

        // Otherwise open a new email-sourced ticket.
        $statusId = $this->statuses->defaultId();
        if ($statusId === null) {
            error_log('[ingest] skip: no default ticket status configured');
            return 'skipped';
        }

        $subject = self::cleanSubject($mail['subject']);
        $newId = $this->tickets->create([
            'customer_id' => $customerId,
            'project_id' => null,
            'status_id' => $statusId,
            'subject' => $subject,
            'description' => $body,
            'priority' => Ticket::DEFAULT_PRIORITY,
            'type' => Ticket::DEFAULT_TYPE,
            'created_by_type' => 'customer',
            'created_by_user_id' => null,
            'source' => 'email',
            'email_message_id' => $messageId,
        ]);
        $this->storeAttachments($customerId, $newId, null, $mail['attachments']);

        if ($this->settings->enabled('notify_admin_on_new')) {
            $this->mailer->notifyNewTicket($newId, $subject, $this->customerName($customerId));
        }
        return 'created';
    }

    /**
     * @param list<array{filename:string,bytes:string,mime:string}> $atts
     */
    private function storeAttachments(int $customerId, int $ticketId, ?int $commentId, array $atts): void
    {
        foreach ($atts as $a) {
            $stored = $this->attachments->storeBytes($customerId, $a['filename'], $a['bytes'], $a['mime']);
            if ($stored === null) {
                continue; // disallowed mime / oversize / unwritable → skip this part
            }
            $this->tickets->addAttachment([
                'ticket_id' => $ticketId,
                'comment_id' => $commentId,
                'filename' => $stored['filename'],
                'storage_path' => $stored['storage_path'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'uploaded_by_type' => 'customer',
            ]);
        }
    }

    private function customerName(int $customerId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM customer WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        $name = $stmt->fetchColumn();
        return $name === false ? 'Kunde' : (string) $name;
    }

    // --- webklex plumbing (not unit-tested; a live send is a manual check) ----

    private function connect(): \Webklex\PHPIMAP\Client
    {
        $cm = new ClientManager();
        $client = $cm->make([
            'host' => $this->host,
            'port' => (int) ($this->port !== '' ? $this->port : 993),
            'encryption' => $this->encryption(),
            'validate_cert' => true,
            'username' => $this->user,
            'password' => $this->pass,
            'protocol' => 'imap',
        ]);
        $client->connect();
        return $client;
    }

    private function encryption(): string|false
    {
        return match ($this->security) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            default => false,
        };
    }

    /**
     * Normalise a webklex Message into the array handle() consumes. Guards every
     * accessor so a malformed part can't abort the whole poll.
     *
     * @return array{message_id:string,from:string,subject:string,references:list<string>,body:string,attachments:list<array{filename:string,bytes:string,mime:string}>}
     */
    private function normalize(\Webklex\PHPIMAP\Message $message): array
    {
        $from = '';
        try {
            $addr = $message->getFrom()->first();
            $from = strtolower(trim((string) ($addr->mail ?? '')));
        } catch (\Throwable) {
            // no parsable From → treated as unknown sender downstream
        }

        $messageId = self::normalizeMessageId((string) $message->getMessageId());
        $subject = (string) $message->getSubject();
        $refsHeader = trim((string) $message->getInReplyTo()) . ' ' . trim((string) $message->getReferences());
        $references = self::extractMessageIds($refsHeader);

        $text = (string) $message->getTextBody();
        if ($text === '') {
            $html = (string) $message->getHTMLBody();
            $text = $html !== '' ? self::htmlToText($html) : '';
        }
        $body = self::clamp(self::stripQuotedReply($text));

        $attachments = [];
        try {
            foreach ($message->getAttachments() as $a) {
                $attachments[] = [
                    'filename' => (string) $a->getName(),
                    'bytes' => (string) $a->getContent(),
                    'mime' => (string) $a->getMimeType(),
                ];
            }
        } catch (\Throwable) {
            // no attachments / parse error → none
        }

        return [
            'message_id' => $messageId,
            'from' => $from,
            'subject' => $subject,
            'references' => $references,
            'body' => $body,
            'attachments' => $attachments,
        ];
    }

    // --- pure helpers (unit-tested) -------------------------------------------

    /** First "#<digits>" marker in a subject → ticket id, else null. */
    public static function parseTicketIdFromSubject(string $subject): ?int
    {
        return preg_match('/#(\d+)/', $subject, $m) ? (int) $m[1] : null;
    }

    /** Strip the surrounding angle brackets from a Message-ID, if present. */
    public static function normalizeMessageId(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '<') && str_ends_with($raw, '>')) {
            $raw = substr($raw, 1, -1);
        }
        return trim($raw);
    }

    /**
     * All bracketed Message-IDs in an In-Reply-To / References header, bare and
     * de-duplicated. Falls back to a single bare id when there are no brackets.
     *
     * @return list<string>
     */
    public static function extractMessageIds(string $header): array
    {
        if (preg_match_all('/<([^>]+)>/', $header, $m)) {
            return array_values(array_unique(array_map('trim', $m[1])));
        }
        $bare = self::normalizeMessageId($header);
        return $bare === '' ? [] : [$bare];
    }

    /**
     * Drop quoted reply history so an appended comment is just the new text.
     * Cuts at the first EN/DE/Outlook reply separator and removes leading '>'
     * quote lines.
     */
    public static function stripQuotedReply(string $body): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '' && (
                preg_match('/^On .+ wrote:$/', $t)                 // Gmail/Apple EN
                || preg_match('/^Am .+ schrieb .+:$/u', $t)        // Gmail DE
                || preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $t)
                || preg_match('/^_{5,}$/', $t)                     // Outlook divider
            )) {
                break;
            }
            if (str_starts_with($t, '>')) {
                continue; // quoted line
            }
            $out[] = $line;
        }
        return trim(implode("\n", $out));
    }

    /** Crude HTML→text for HTML-only mails (no text/plain part). */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|tr|h[1-6]|li)>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /** Strip leading reply prefixes, clamp to 200 chars, fall back when empty. */
    public static function cleanSubject(string $subject): string
    {
        $s = trim($subject);
        while (preg_match('/^(re|aw|fwd|fw|wg)\s*:\s*/i', $s)) {
            $s = trim((string) preg_replace('/^(re|aw|fwd|fw|wg)\s*:\s*/i', '', $s, 1));
        }
        $s = mb_substr($s, 0, 200);
        return $s !== '' ? $s : 'E-Mail-Anfrage';
    }

    private static function clamp(string $text): string
    {
        return mb_substr($text, 0, self::MAX_BODY);
    }
}
