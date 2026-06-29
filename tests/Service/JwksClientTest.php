<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Service\JwksClient;

/**
 * Exercises the JWKS-backed token verifier with a real RSA keypair (so
 * the crypto path is genuinely tested) and a mocked HTTP transport (so
 * the fetch + on-disk cache behaviour is observable). Requires OPENSSL_CONF
 * to point at a valid openssl.cnf for openssl_pkey_export (same as the
 * auth-api keygen tests).
 */
final class JwksClientTest extends TestCase
{
    private string $privatePem;
    private string $jwksJson;
    private string $cacheDir;

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            self::markTestSkipped('openssl_pkey_new failed — OPENSSL_CONF not set?');
        }
        openssl_pkey_export($res, $pem);
        $this->privatePem = $pem;
        $details = openssl_pkey_get_details($res);

        $b64url = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $this->jwksJson = json_encode([
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => 'test-key-1',
                'n' => $b64url($details['rsa']['n']),
                'e' => $b64url($details['rsa']['e']),
            ]],
        ]);

        $this->cacheDir = sys_get_temp_dir() . '/jwks-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $f = $this->cacheDir . '/jwks.json';
        if (is_file($f)) {
            unlink($f);
        }
        if (is_dir($this->cacheDir)) {
            @rmdir($this->cacheDir);
        }
    }

    private function token(array $claims): string
    {
        return JWT::encode($claims, $this->privatePem, 'RS256', 'test-key-1');
    }

    private function client(MockHandler $mock): JwksClient
    {
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        return new JwksClient($http, 'https://auth.example/.well-known/jwks.json', $this->cacheDir, 300);
    }

    public function test_verifies_a_valid_token_and_returns_its_claims(): void
    {
        $client = $this->client(new MockHandler([new Response(200, [], $this->jwksJson)]));

        $claims = $client->verify($this->token(['sub' => 'cust_42', 'role' => 'customer', 'exp' => time() + 3600]));

        self::assertSame('cust_42', $claims['sub']);
        self::assertSame('customer', $claims['role']);
    }

    public function test_caches_jwks_so_a_second_verify_skips_the_http_fetch(): void
    {
        // Only ONE queued HTTP response: the second verify must hit the
        // on-disk cache or the MockHandler would throw "Mock queue empty".
        $client = $this->client(new MockHandler([new Response(200, [], $this->jwksJson)]));

        $a = $client->verify($this->token(['sub' => 'cust_1', 'exp' => time() + 3600]));
        $b = $client->verify($this->token(['sub' => 'cust_2', 'exp' => time() + 3600]));

        self::assertSame('cust_1', $a['sub']);
        self::assertSame('cust_2', $b['sub']);
        self::assertFileExists($this->cacheDir . '/jwks.json');
    }

    public function test_rejects_a_jwks_response_without_a_keys_array(): void
    {
        $client = $this->client(new MockHandler([new Response(200, [], '{"oops":true}')]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JWKS response');
        $client->verify($this->token(['sub' => 'cust_1', 'exp' => time() + 3600]));
    }

    public function test_rejects_an_expired_token(): void
    {
        $client = $this->client(new MockHandler([new Response(200, [], $this->jwksJson)]));

        $this->expectException(\Firebase\JWT\ExpiredException::class);
        $client->verify($this->token(['sub' => 'cust_1', 'exp' => time() - 10]));
    }

    public function test_rejects_a_token_signed_by_a_foreign_key(): void
    {
        $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($foreign, $foreignPem);
        $forged = JWT::encode(['sub' => 'attacker', 'exp' => time() + 3600], $foreignPem, 'RS256', 'test-key-1');

        $client = $this->client(new MockHandler([new Response(200, [], $this->jwksJson)]));

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        $client->verify($forged);
    }
}
