<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Service;

use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Service\DocumentSigner;

final class DocumentSignerTest extends TestCase
{
    public function test_empty_secret_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocumentSigner('');
    }

    public function test_sign_then_verify_round_trips(): void
    {
        $signer = new DocumentSigner('s3cret-do-not-share');
        $exp = time() + 60;

        $sig = $signer->sign(documentId: 42, customerId: 7, exp: $exp);

        self::assertTrue($signer->verify(42, 7, $exp, $sig));
    }

    public function test_verify_rejects_expired(): void
    {
        $signer = new DocumentSigner('s3cret');
        $exp = time() - 1;
        $sig = $signer->sign(7, 1, $exp);

        self::assertFalse($signer->verify(7, 1, $exp, $sig));
    }

    public function test_verify_rejects_tampered_signature(): void
    {
        $signer = new DocumentSigner('s3cret');
        $exp = time() + 60;
        $sig = $signer->sign(7, 1, $exp);

        $tampered = substr($sig, 0, -1) . (substr($sig, -1) === 'a' ? 'b' : 'a');
        self::assertFalse($signer->verify(7, 1, $exp, $tampered));
    }

    public function test_verify_rejects_when_customer_does_not_match(): void
    {
        $signer = new DocumentSigner('s3cret');
        $exp = time() + 60;
        $sig = $signer->sign(7, 1, $exp);

        self::assertFalse(
            $signer->verify(7, 2, $exp, $sig),
            'a signature for customer 1 must not authorize customer 2',
        );
    }

    public function test_verify_rejects_with_wrong_secret(): void
    {
        $exp = time() + 60;
        $a = new DocumentSigner('secret-a');
        $b = new DocumentSigner('secret-b');

        $sig = $a->sign(7, 1, $exp);
        self::assertFalse($b->verify(7, 1, $exp, $sig));
    }
}
