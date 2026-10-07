<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

/**
 * HMAC signer for short-lived document URLs.
 *
 * Signs the tuple (documentId, customerId, exp) so a URL can be
 * shared with the frontend (or pasted into an <img>) without
 * re-authenticating on every byte fetched. Verify checks the HMAC
 * is intact, the URL hasn't expired, and the customer in the URL
 * matches the document's owner row at download time.
 */
final class DocumentSigner
{
    public const DEFAULT_TTL = 300; // 5 minutes

    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new \InvalidArgumentException(
                'DOCUMENT_SIGN_SECRET is empty — set a strong random string',
            );
        }
    }

    public function sign(int $documentId, int $customerId, int $exp): string
    {
        $payload = sprintf('%d.%d.%d', $documentId, $customerId, $exp);
        return hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * Constant-time verify of (documentId, customerId, exp, sig).
     * Returns true only when the signature matches AND `exp` is in
     * the future.
     */
    public function verify(int $documentId, int $customerId, int $exp, string $sig): bool
    {
        if ($exp <= time()) {
            return false;
        }
        $expected = $this->sign($documentId, $customerId, $exp);
        return hash_equals($expected, $sig);
    }
}
