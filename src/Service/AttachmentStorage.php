<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Persists ticket attachment bytes on disk, mirroring the Document storage model
 * (same DOCUMENT_ROOT_DIR, same mime allowlist + size cap). Files land under
 * {root}/{customer_id}/tickets/{uuid}-{name}. Shared between the customer and
 * admin upload paths so the file handling stays in one place.
 */
final class AttachmentStorage
{
    public const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function rootDir(): string
    {
        return (string) (getenv('DOCUMENT_ROOT_DIR') ?: '');
    }

    public function available(): bool
    {
        $root = $this->rootDir();
        return $root !== '' && is_dir($root) && is_writable($root);
    }

    /**
     * Store an uploaded file and return its metadata. Throws on any problem so
     * the caller can map it to the right HTTP status.
     *
     * @return array{filename:string,storage_path:string,mime_type:string,size_bytes:int}
     */
    public function store(int $customerId, UploadedFileInterface $file): array
    {
        $mime = $file->getClientMediaType() ?? 'application/octet-stream';
        $root = $this->rootDir();
        $dir = $root . DIRECTORY_SEPARATOR . $customerId . DIRECTORY_SEPARATOR . 'tickets';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) $file->getClientFilename()) ?: 'file';
        $uuid = bin2hex(random_bytes(8));
        $relPath = $customerId . '/tickets/' . $uuid . '-' . $safeName;
        $file->moveTo($root . DIRECTORY_SEPARATOR . $relPath);

        return [
            'filename' => $safeName,
            'storage_path' => $relPath,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    /**
     * Store raw bytes (a decoded email MIME part) and return its metadata, or
     * null when the part is disallowed/oversize/unwritable — the IMAP ingester
     * skips it rather than failing the whole message. Mirrors store(): same
     * {root}/{customer_id}/tickets/{uuid}-{name} layout + name sanitising, but
     * takes bytes instead of a PSR-7 uploaded file.
     *
     * @return array{filename:string,storage_path:string,mime_type:string,size_bytes:int}|null
     */
    public function storeBytes(int $customerId, string $filename, string $bytes, string $mime): ?array
    {
        $size = strlen($bytes);
        if (!$this->available() || $size === 0 || $size > self::MAX_BYTES) {
            return null;
        }
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return null;
        }

        $root = $this->rootDir();
        $dir = $root . DIRECTORY_SEPARATOR . $customerId . DIRECTORY_SEPARATOR . 'tickets';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: 'file';
        $uuid = bin2hex(random_bytes(8));
        $relPath = $customerId . '/tickets/' . $uuid . '-' . $safeName;
        if (file_put_contents($root . DIRECTORY_SEPARATOR . $relPath, $bytes) === false) {
            return null;
        }

        return [
            'filename' => $safeName,
            'storage_path' => $relPath,
            'mime_type' => $mime,
            'size_bytes' => $size,
        ];
    }

    public function absolutePath(string $storagePath): string
    {
        return $this->rootDir() . DIRECTORY_SEPARATOR . $storagePath;
    }
}
