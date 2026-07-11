<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use Tds\CustomerApi\Service\AttachmentStorage;
use Tds\CustomerApi\Service\ImapTicketIngest;
use Tds\CustomerApi\Service\SmtpMailer;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;
use Tds\CustomerApi\Service\TicketStatusRepository;
use Tds\CustomerApi\Tests\Support\DbTestCase;

/**
 * DB-backed coverage for the IMAP → ticket persistence (handle()). Skips unless
 * TDS_TEST_DB_DSN points at a real MariaDB/MySQL. Exercises the logic off a
 * normalised message array, so no live mailbox / webklex is involved.
 */
final class ImapTicketIngestTest extends DbTestCase
{
    private ImapTicketIngest $ingest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerTable();
        $this->createTicketStatusTable();
        $this->createTicketTable();
        $this->createTicketCommentTable();
        $this->createTicketAttachmentTable();
        $this->createTicketSettingTable();
        $this->seedTicketStatuses();
        $this->pdo->exec("INSERT INTO customer (id, email, name) VALUES (7, 'c@example.com', 'Acme GmbH')");
        $this->pdo->exec("INSERT INTO customer (id, email, name) VALUES (9, 'other@example.com', 'Other Co')");

        // Unconfigured SMTP → mailer no-ops; IMAP creds empty (handle() never
        // touches the mailbox — only poll()/connect() do).
        $smtp = new SmtpMailer(host: '', port: '', user: '', pass: '', security: 'tls', from: '');
        $mailer = new TicketMailer($smtp, 'admin@x', 'support@x', 'https://m', 'https://a');
        $this->ingest = new ImapTicketIngest(
            $this->pdo,
            new TicketRepository($this->pdo),
            new TicketStatusRepository($this->pdo),
            new AttachmentStorage(),
            $mailer,
            new TicketSettings($this->pdo),
            host: '',
            port: '',
            user: '',
            pass: '',
            security: 'ssl',
            folder: 'INBOX',
        );
    }

    /** @param array<string,mixed> $overrides */
    private function mail(array $overrides = []): array
    {
        return array_merge([
            'message_id' => 'msg-' . bin2hex(random_bytes(4)) . '@example.com',
            'from' => 'c@example.com',
            'subject' => 'Login klappt nicht',
            'references' => [],
            'body' => 'Ich komme nicht mehr rein.',
            'attachments' => [],
        ], $overrides);
    }

    public function test_unknown_sender_is_skipped(): void
    {
        $outcome = $this->ingest->handle($this->mail(['from' => 'stranger@nope.com']));
        self::assertSame('skipped', $outcome);
        self::assertSame('0', (string) $this->pdo->query('SELECT COUNT(*) FROM ticket')->fetchColumn());
    }

    public function test_known_sender_creates_email_ticket(): void
    {
        $outcome = $this->ingest->handle($this->mail(['message_id' => 'first@x']));
        self::assertSame('created', $outcome);

        $row = $this->pdo->query('SELECT customer_id, source, email_message_id, subject FROM ticket LIMIT 1')->fetch();
        self::assertSame(7, (int) $row['customer_id']);
        self::assertSame('email', $row['source']);
        self::assertSame('first@x', $row['email_message_id']);
        self::assertSame('Login klappt nicht', $row['subject']);
    }

    public function test_duplicate_message_id_is_skipped(): void
    {
        self::assertSame('created', $this->ingest->handle($this->mail(['message_id' => 'dupe@x'])));
        self::assertSame('skipped', $this->ingest->handle($this->mail(['message_id' => 'dupe@x'])));
        self::assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM ticket')->fetchColumn());
    }

    public function test_reply_with_subject_marker_appends_comment(): void
    {
        $this->ingest->handle($this->mail(['message_id' => 'orig@x', 'subject' => 'Login klappt nicht']));
        $ticketId = (int) $this->pdo->query('SELECT id FROM ticket LIMIT 1')->fetchColumn();
        // Mark it as needing customer action, to prove the reply clears it.
        $this->pdo->exec("UPDATE ticket SET customer_action_required = 1 WHERE id = {$ticketId}");

        $outcome = $this->ingest->handle($this->mail([
            'message_id' => 'reply@x',
            'subject' => "Re: Ticket #{$ticketId}: Login klappt nicht",
            'body' => 'Jetzt geht es wieder.',
        ]));

        self::assertSame('appended', $outcome);
        self::assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM ticket')->fetchColumn());
        $comment = $this->pdo->query("SELECT author_type, body, email_message_id FROM ticket_comment WHERE ticket_id = {$ticketId}")->fetch();
        self::assertSame('customer', $comment['author_type']);
        self::assertSame('Jetzt geht es wieder.', $comment['body']);
        self::assertSame('reply@x', $comment['email_message_id']);
        self::assertSame('0', (string) $this->pdo->query("SELECT customer_action_required FROM ticket WHERE id = {$ticketId}")->fetchColumn());
    }

    public function test_reply_marker_for_another_customers_ticket_opens_new(): void
    {
        // Customer 7 opens a ticket.
        $this->ingest->handle($this->mail(['message_id' => 'c7@x']));
        $ticketId = (int) $this->pdo->query('SELECT id FROM ticket LIMIT 1')->fetchColumn();

        // Customer 9 mails with #<ticketId> — must NOT append to customer 7's
        // ticket; a new ticket is opened for customer 9 instead.
        $outcome = $this->ingest->handle($this->mail([
            'from' => 'other@example.com',
            'message_id' => 'c9@x',
            'subject' => "Re: Ticket #{$ticketId}: something",
        ]));

        self::assertSame('created', $outcome);
        self::assertSame('2', (string) $this->pdo->query('SELECT COUNT(*) FROM ticket')->fetchColumn());
        self::assertSame('0', (string) $this->pdo->query("SELECT COUNT(*) FROM ticket_comment WHERE ticket_id = {$ticketId}")->fetchColumn());
    }
}
