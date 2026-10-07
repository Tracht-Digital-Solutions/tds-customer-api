<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;

/**
 * Fetches JWKS from tds-auth-api and caches it on disk for the
 * configured TTL. Verifies bearer tokens against the cached keys.
 */
final class JwksClient implements TokenVerifier
{
    public function __construct(
        private readonly Client $http,
        private readonly string $jwksUrl,
        private readonly string $cacheDir,
        private readonly int $cacheTtl,
    ) {
    }

    /**
     * Verify a JWT and return its decoded claims. Throws on any failure.
     *
     * @return array<string, mixed>
     */
    public function verify(string $jwt): array
    {
        $jwks = $this->loadJwks();
        $keys = JWK::parseKeySet($jwks);

        $decoded = JWT::decode($jwt, $keys);
        return (array) $decoded;
    }

    /** @return array<string, mixed> */
    private function loadJwks(): array
    {
        $cacheFile = $this->cacheDir . '/jwks.json';
        if (
            is_file($cacheFile)
            && filemtime($cacheFile) > time() - $this->cacheTtl
        ) {
            $raw = file_get_contents($cacheFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $resp = $this->http->get($this->jwksUrl);
        $raw = (string) $resp->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['keys'])) {
            throw new \RuntimeException("Invalid JWKS response from {$this->jwksUrl}");
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $raw);

        return $decoded;
    }
}
