<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Service\ImapTicketIngest;

/**
 * Pure-function coverage for the IMAP message parsing helpers — no mailbox, no
 * DB, so these run everywhere. handle()/poll() DB behaviour is in the DB-backed
 * ImapTicketIngestTest; a live fetch is a manual check (INSTALL.md).
 */
final class ImapTicketIngestParseTest extends TestCase
{
    public function test_parse_ticket_id_from_subject(): void
    {
        self::assertSame(42, ImapTicketIngest::parseTicketIdFromSubject('Re: Ticket #42: Login'));
        self::assertSame(7, ImapTicketIngest::parseTicketIdFromSubject('AW: Ticket #7 Neue Antwort'));
        self::assertNull(ImapTicketIngest::parseTicketIdFromSubject('No number here'));
        self::assertNull(ImapTicketIngest::parseTicketIdFromSubject('Rechnung 2024 fällig'));
    }

    public function test_normalize_message_id_strips_brackets(): void
    {
        self::assertSame('abc@host', ImapTicketIngest::normalizeMessageId('<abc@host>'));
        self::assertSame('abc@host', ImapTicketIngest::normalizeMessageId('  <abc@host> '));
        self::assertSame('bare@host', ImapTicketIngest::normalizeMessageId('bare@host'));
    }

    public function test_extract_message_ids(): void
    {
        self::assertSame(
            ['a@h', 'b@h'],
            ImapTicketIngest::extractMessageIds('<a@h> <b@h>'),
        );
        // In-Reply-To + References combined, duplicates collapsed.
        self::assertSame(
            ['first@h', 'second@h'],
            ImapTicketIngest::extractMessageIds('<first@h> <first@h> <second@h>'),
        );
        self::assertSame(['bare@h'], ImapTicketIngest::extractMessageIds('bare@h'));
        self::assertSame([], ImapTicketIngest::extractMessageIds('   '));
    }

    public function test_strip_quoted_reply_cuts_at_separator(): void
    {
        $body = "Das klappt jetzt, danke!\n\nOn Mon, 7 Jul 2026 at 10:00, Support wrote:\n> Können Sie es erneut versuchen?";
        self::assertSame('Das klappt jetzt, danke!', ImapTicketIngest::stripQuotedReply($body));

        $german = "Passt.\n\nAm 07.07.2026 schrieb Support <s@x>:\n> alte Nachricht";
        self::assertSame('Passt.', ImapTicketIngest::stripQuotedReply($german));
    }

    public function test_strip_quoted_reply_drops_quote_lines(): void
    {
        $body = "Neue Antwort.\n> zitierte Zeile\nNoch eine Zeile.";
        self::assertSame("Neue Antwort.\nNoch eine Zeile.", ImapTicketIngest::stripQuotedReply($body));
    }

    public function test_clean_subject_strips_prefixes_and_falls_back(): void
    {
        self::assertSame('Login klappt nicht', ImapTicketIngest::cleanSubject('Re: Login klappt nicht'));
        self::assertSame('Frage', ImapTicketIngest::cleanSubject('AW: Re: WG: Frage'));
        self::assertSame('E-Mail-Anfrage', ImapTicketIngest::cleanSubject('   '));
        self::assertSame('E-Mail-Anfrage', ImapTicketIngest::cleanSubject('Re:'));
    }

    public function test_html_to_text(): void
    {
        $html = '<p>Hallo,</p><p>das ist ein Test &amp; mehr.</p><br>Ende';
        $text = ImapTicketIngest::htmlToText($html);
        self::assertStringContainsString('Hallo,', $text);
        self::assertStringContainsString('das ist ein Test & mehr.', $text);
        self::assertStringContainsString('Ende', $text);
        self::assertStringNotContainsString('<p>', $text);
    }
}
