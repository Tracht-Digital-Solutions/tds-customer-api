<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

interface TokenVerifier
{
    /**
     * Verify the JWT and return its decoded claims. Throws on any
     * failure (bad signature, expired, malformed).
     *
     * @return array<string, mixed>
     */
    public function verify(string $jwt): array;
}
