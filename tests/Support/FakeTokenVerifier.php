<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Support;

use Tds\CustomerApi\Service\TokenVerifier;

final class FakeTokenVerifier implements TokenVerifier
{
    public ?\Throwable $throwOnVerify = null;

    /** @var array<string,mixed>|null */
    public ?array $claims = null;

    public string $lastToken = '';

    /** @return array<string,mixed> */
    public function verify(string $jwt): array
    {
        $this->lastToken = $jwt;
        if ($this->throwOnVerify !== null) {
            throw $this->throwOnVerify;
        }
        return $this->claims ?? [];
    }
}
