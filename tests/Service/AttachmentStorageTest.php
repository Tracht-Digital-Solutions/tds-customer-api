<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Service\AttachmentStorage;

/**
 * Where ticket attachment bytes land on disk.
 *
 * Two of the three guards here are the only thing between an untrusted upload
 * and the filesystem:
 *
 *  - the **filename is sanitised** before it is used as a path segment. The
 *    bytes arrive from a customer upload or, worse, from an IMAP message — a
 *    name of `../../etc/passwd` must not escape the customer's own directory;
 *  - the **mime allowlist and size cap** decide what is stored at all.
 *
 * `storeBytes()` is the IMAP-ingest path and returns `null` rather than
 * throwing, because one bad MIME part must not fail a whole incoming email —
 * so every rejection is asserted to be a null, not an exception.
 */
final class AttachmentStorageTest extends TestCase
{
    private string $root;
    private AttachmentStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tds-attach-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        self::setRoot($this->root);
        $this->storage = new AttachmentStorage();
    }

    protected function tearDown(): void
    {
        self::setRoot(null);
        $this->rmrf($this->root);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rmrf($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    // --- availability -----------------------------------------------------

    public function test_is_available_with_a_writable_root(): void
    {
        self::assertTrue($this->storage->available());
        self::assertSame($this->root, $this->storage->rootDir());
    }

    public function test_is_UNAVAILABLE_when_no_root_is_configured(): void
    {
        // Uploads must 503 rather than write into the process working dir.
        self::setRoot('');
        self::assertFalse($this->storage->available());
        self::assertSame('', $this->storage->rootDir());
    }

    public function test_is_unavailable_when_the_root_does_not_exist(): void
    {
        self::setRoot($this->root . '/nope');
        self::assertFalse($this->storage->available());
    }

    // --- the mime allowlist -----------------------------------------------

    public function test_stores_an_allowed_type(): void
    {
        $meta = $this->storage->storeBytes(7, 'angebot.pdf', '%PDF-1.4', 'application/pdf');

        self::assertNotNull($meta);
        self::assertSame('angebot.pdf', $meta['filename']);
        self::assertSame('application/pdf', $meta['mime_type']);
        self::assertSame(8, $meta['size_bytes']);
    }

    public function test_REJECTS_a_type_outside_the_allowlist(): void
    {
        // An executable or a script must never reach disk, whatever the
        // sending mail client claims.
        foreach (['application/x-msdownload', 'text/html', 'image/svg+xml', 'application/zip'] as $mime) {
            self::assertNull($this->storage->storeBytes(7, 'evil', 'bytes', $mime), $mime);
        }
    }

    public function test_accepts_every_type_on_the_allowlist(): void
    {
        foreach (AttachmentStorage::ALLOWED_MIME as $i => $mime) {
            self::assertNotNull($this->storage->storeBytes(7, "f{$i}.bin", 'bytes', $mime), $mime);
        }
    }

    public function test_the_allowlist_carries_no_executable_or_markup_type(): void
    {
        // The list itself is the policy; a stray text/html would let a stored
        // attachment be served back as an active page.
        foreach (['text/html', 'image/svg+xml', 'application/x-msdownload', 'application/javascript'] as $mime) {
            self::assertNotContains($mime, AttachmentStorage::ALLOWED_MIME, $mime);
        }
    }

    // --- the size cap ------------------------------------------------------

    public function test_rejects_an_empty_part(): void
    {
        self::assertNull($this->storage->storeBytes(7, 'empty.pdf', '', 'application/pdf'));
    }

    public function test_rejects_a_part_over_the_cap(): void
    {
        $tooBig = str_repeat('x', AttachmentStorage::MAX_BYTES + 1);
        self::assertNull($this->storage->storeBytes(7, 'big.pdf', $tooBig, 'application/pdf'));
    }

    public function test_accepts_a_part_exactly_at_the_cap(): void
    {
        // An off-by-one here rejects a legitimate 25 MB attachment.
        $atCap = str_repeat('x', AttachmentStorage::MAX_BYTES);
        $meta = $this->storage->storeBytes(7, 'max.pdf', $atCap, 'application/pdf');

        self::assertNotNull($meta);
        self::assertSame(AttachmentStorage::MAX_BYTES, $meta['size_bytes']);
    }

    public function test_the_cap_is_25_mb(): void
    {
        self::assertSame(25 * 1024 * 1024, AttachmentStorage::MAX_BYTES);
    }

    public function test_returns_null_rather_than_throwing_when_unavailable(): void
    {
        // The IMAP ingester skips a part; it must not fail the whole message.
        self::setRoot('');
        self::assertNull($this->storage->storeBytes(7, 'a.pdf', 'bytes', 'application/pdf'));
    }

    // --- filename sanitising (the path-traversal guard) --------------------

    public function test_STRIPS_path_separators_from_the_filename(): void
    {
        // The name arrives from an upload or an email; without this it is a
        // path, not a name.
        $meta = $this->storage->storeBytes(7, '../../etc/passwd', 'bytes', 'application/pdf');

        self::assertNotNull($meta);
        self::assertStringNotContainsString('/', $meta['filename']);
        self::assertStringNotContainsString('\\', $meta['filename']);
    }

    public function test_keeps_the_stored_file_inside_the_customers_own_directory(): void
    {
        $meta = $this->storage->storeBytes(7, '../../escape.pdf', 'bytes', 'application/pdf');

        self::assertNotNull($meta);
        self::assertStringStartsWith('7/tickets/', $meta['storage_path']);
        $written = realpath($this->storage->absolutePath($meta['storage_path']));
        self::assertNotFalse($written);
        self::assertStringStartsWith(realpath($this->root) ?: $this->root, $written);
    }

    public function test_replaces_every_unsafe_character(): void
    {
        $meta = $this->storage->storeBytes(7, 'a b;c&d|e$f.pdf', 'bytes', 'application/pdf');

        self::assertNotNull($meta);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9._-]+$/', $meta['filename']);
    }

    public function test_keeps_a_readable_name_intact(): void
    {
        $meta = $this->storage->storeBytes(7, 'Angebot_2026-07.pdf', 'bytes', 'application/pdf');

        self::assertNotNull($meta);
        self::assertSame('Angebot_2026-07.pdf', $meta['filename']);
    }

    public function test_falls_back_to_a_generic_name_when_nothing_survives(): void
    {
        // `preg_replace` returning "" would otherwise make the path end in "-".
        $meta = $this->storage->storeBytes(7, '///', 'bytes', 'application/pdf');

        self::assertNotNull($meta);
        self::assertNotSame('', $meta['filename']);
    }

    // --- the storage layout ------------------------------------------------

    public function test_lays_files_out_per_customer(): void
    {
        // The download route authorises by customer; a shared directory would
        // put one customer's attachment inside another's path.
        $a = $this->storage->storeBytes(7, 'a.pdf', 'bytes', 'application/pdf');
        $b = $this->storage->storeBytes(9, 'b.pdf', 'bytes', 'application/pdf');

        self::assertStringStartsWith('7/tickets/', $a['storage_path']);
        self::assertStringStartsWith('9/tickets/', $b['storage_path']);
    }

    public function test_creates_the_customer_directory_on_demand(): void
    {
        self::assertDirectoryDoesNotExist($this->root . '/7/tickets');
        $this->storage->storeBytes(7, 'a.pdf', 'bytes', 'application/pdf');
        self::assertDirectoryExists($this->root . '/7/tickets');
    }

    public function test_gives_two_uploads_of_the_SAME_NAME_distinct_paths(): void
    {
        // Without the uuid the second upload silently overwrites the first.
        $a = $this->storage->storeBytes(7, 'rechnung.pdf', 'eins', 'application/pdf');
        $b = $this->storage->storeBytes(7, 'rechnung.pdf', 'zwei', 'application/pdf');

        self::assertNotSame($a['storage_path'], $b['storage_path']);
        self::assertSame('eins', file_get_contents($this->storage->absolutePath($a['storage_path'])));
        self::assertSame('zwei', file_get_contents($this->storage->absolutePath($b['storage_path'])));
    }

    public function test_writes_the_bytes_verbatim(): void
    {
        $bytes = "binary\0content\xff";
        $meta = $this->storage->storeBytes(7, 'raw.pdf', $bytes, 'application/pdf');

        self::assertSame($bytes, file_get_contents($this->storage->absolutePath($meta['storage_path'])));
    }

    public function test_resolves_a_storage_path_under_the_root(): void
    {
        self::assertSame(
            $this->root . DIRECTORY_SEPARATOR . '7/tickets/abc-a.pdf',
            $this->storage->absolutePath('7/tickets/abc-a.pdf'),
        );
    }

    /**
     * Set (or clear, with null) the storage root the way production sees it:
     * `$_ENV` first — where phpdotenv puts `.env` values — then the process
     * environment. A test earlier in the run may have loaded the repo's own
     * `.env` into `$_ENV`, so `putenv()` alone no longer decides.
     */
    private static function setRoot(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['DOCUMENT_ROOT_DIR'], $_SERVER['DOCUMENT_ROOT_DIR']);
            putenv('DOCUMENT_ROOT_DIR');
            return;
        }
        $_ENV['DOCUMENT_ROOT_DIR'] = $value;
        putenv('DOCUMENT_ROOT_DIR=' . $value);
    }
}
