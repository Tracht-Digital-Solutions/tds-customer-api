<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Service\AttachmentStorage;
use Tds\CustomerApi\Service\TicketRepository;

/** POST /tickets/{id}/attachments — multipart upload under "file", scoped to the customer's ticket. */
final class AttachmentUploadAction extends BaseAction
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly AttachmentStorage $storage,
    ) {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $customerId = $this->customerId($request);
        $id = (int) ($args['id'] ?? 0);

        $row = $this->tickets->findRow($id, $customerId);
        if ($row === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $claims = $request->getAttribute(JwksAuthMiddleware::ATTR_CLAIMS);
        $isAdmin = is_array($claims) ? (bool) ($claims['admin'] ?? false) : false;

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, 400, ['error' => 'No valid file uploaded under "file"']);
        }
        if ($file->getSize() === null || $file->getSize() > AttachmentStorage::MAX_BYTES) {
            return $this->json($response, 413, ['error' => 'File exceeds 25 MB limit']);
        }
        $mime = $file->getClientMediaType() ?? 'application/octet-stream';
        if (!in_array($mime, AttachmentStorage::ALLOWED_MIME, true)) {
            return $this->json($response, 415, ['error' => 'Mime type not allowed', 'mime' => $mime]);
        }
        if (!$this->storage->available()) {
            return $this->json($response, 503, ['error' => 'Attachment storage unavailable']);
        }

        $meta = $this->storage->store($customerId, $file);
        $attachmentId = $this->tickets->addAttachment([
            'ticket_id' => $id,
            'comment_id' => null,
            'filename' => $meta['filename'],
            'storage_path' => $meta['storage_path'],
            'mime_type' => $meta['mime_type'],
            'size_bytes' => $meta['size_bytes'],
            'uploaded_by_type' => $isAdmin ? 'owner' : 'customer',
        ]);
        $this->tickets->touch($id);

        return $this->json($response, 201, ['id' => $attachmentId, 'filename' => $meta['filename']]);
    }
}
