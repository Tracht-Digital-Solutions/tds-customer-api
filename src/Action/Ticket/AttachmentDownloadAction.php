<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Ticket;

use Slim\Psr7\Factory\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;
use Tds\CustomerApi\Service\AttachmentStorage;
use Tds\CustomerApi\Service\TicketRepository;

/** GET /tickets/{id}/attachments/{aid} — stream an attachment on the customer's ticket. */
final class AttachmentDownloadAction extends BaseAction
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
        $attachmentId = (int) ($args['aid'] ?? 0);

        // Confirm the ticket belongs to the customer before serving its file.
        if ($this->tickets->findRow($id, $customerId) === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }
        $att = $this->tickets->findAttachment($attachmentId, $id);
        if ($att === null) {
            return $this->json($response, 404, ['error' => 'Not found']);
        }

        $absPath = $this->storage->absolutePath((string) $att['storage_path']);
        if (!is_readable($absPath)) {
            return $this->json($response, 410, ['error' => 'File no longer present on disk']);
        }

        $stream = (new StreamFactory())->createStreamFromFile($absPath, 'rb');
        return $response
            ->withHeader('Content-Type', (string) $att['mime_type'])
            ->withHeader('Content-Length', (string) $att['size_bytes'])
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', addslashes((string) $att['filename'])))
            ->withBody($stream);
    }
}
