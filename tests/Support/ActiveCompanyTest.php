<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Tests\Support;

use PHPUnit\Framework\TestCase;
use Tds\CustomerApi\Support\ActiveCompany;

/**
 * Unit tests for the active-company / per-company-permission resolution shared
 * by BaseAction and RequirePermissionMiddleware.
 */
final class ActiveCompanyTest extends TestCase
{
    /** @return array<string,mixed> */
    private function multiCompanyClaims(): array
    {
        return [
            'admin' => false,
            'customer_id' => 3,
            'permissions' => ['tickets:read', 'tickets:write'],
            'companies' => [
                ['id' => 3, 'permissions' => ['tickets:read', 'tickets:write']],
                ['id' => 5, 'permissions' => ['invoices:read']],
            ],
        ];
    }

    public function test_allowed_ids_from_companies_claim(): void
    {
        self::assertSame([3, 5], ActiveCompany::allowedIds($this->multiCompanyClaims()));
    }

    public function test_allowed_ids_fall_back_to_customer_id(): void
    {
        self::assertSame([7], ActiveCompany::allowedIds(['customer_id' => 7]));
    }

    public function test_resolve_honours_header_when_a_member(): void
    {
        self::assertSame(5, ActiveCompany::resolve($this->multiCompanyClaims(), '5'));
    }

    public function test_resolve_ignores_header_for_non_member(): void
    {
        // Company 9 isn't in the membership list → fall back to primary (3).
        self::assertSame(3, ActiveCompany::resolve($this->multiCompanyClaims(), '9'));
    }

    public function test_resolve_defaults_to_primary_without_header(): void
    {
        self::assertSame(3, ActiveCompany::resolve($this->multiCompanyClaims(), ''));
    }

    public function test_permissions_are_scoped_to_the_active_company(): void
    {
        $claims = $this->multiCompanyClaims();
        self::assertSame(['tickets:read', 'tickets:write'], ActiveCompany::permissionsFor($claims, 3));
        self::assertSame(['invoices:read'], ActiveCompany::permissionsFor($claims, 5));
    }

    public function test_permissions_fall_back_to_flat_claim_for_legacy_token(): void
    {
        // No companies claim (token issued before multi-company).
        $claims = ['customer_id' => 7, 'permissions' => ['documents:read']];
        self::assertSame(['documents:read'], ActiveCompany::permissionsFor($claims, 7));
    }

    public function test_permissions_empty_for_unknown_company(): void
    {
        // A company the login isn't a member of yields no permissions (no flat
        // fallback because a companies claim is present).
        self::assertSame([], ActiveCompany::permissionsFor($this->multiCompanyClaims(), 99));
    }
}
