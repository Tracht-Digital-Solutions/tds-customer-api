<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Support;

/**
 * Resolves the *active company* and its permissions for a portal request, from
 * the JWT claims + the `X-Act-As-Customer` header.
 *
 * A non-admin login can belong to several companies (the JWT `companies` claim,
 * `[{id, permissions}]`); the portal picks one active company per session and
 * sends it as `X-Act-As-Customer`. This helper is the single place that decides
 * which company a request is scoped to and which permission set applies, shared
 * by `BaseAction::customerId()` and `RequirePermissionMiddleware` so they never
 * disagree.
 *
 * Backward compatibility: a token issued before multi-company (no `companies`
 * claim) falls back to the flat `customer_id` / `permissions` claims.
 */
final class ActiveCompany
{
    public const HEADER = 'X-Act-As-Customer';

    /** The renamed header the frontend API reads; accepted here too. */
    public const COMPANY_HEADER = 'X-Act-As-Company';

    /**
     * The primary company from the token: `company_id`, else the deprecated
     * `customer_id` alias auth-api emits for one release only. Reading only
     * `customer_id` would have 401ed every portal user the day it was dropped.
     *
     * @param array<string,mixed> $claims
     */
    public static function primaryId(array $claims): ?int
    {
        foreach (['company_id', 'customer_id'] as $key) {
            $value = $claims[$key] ?? null;
            if (is_int($value) && $value > 0) {
                return $value;
            }
        }
        return null;
    }

    /** The act-as header of a request, under either name (new name first). */
    public static function headerOf(\Psr\Http\Message\ServerRequestInterface $request): string
    {
        $value = trim($request->getHeaderLine(self::COMPANY_HEADER));
        return $value !== '' ? $value : trim($request->getHeaderLine(self::HEADER));
    }

    /**
     * The company ids a non-admin login may act as. Derived from the `companies`
     * claim, falling back to the single `customer_id` claim.
     *
     * @param array<string,mixed> $claims
     * @return list<int>
     */
    public static function allowedIds(array $claims): array
    {
        $ids = [];
        foreach (self::companies($claims) as $c) {
            $ids[] = $c['id'];
        }
        if ($ids === []) {
            $cid = self::primaryId($claims);
            if ($cid !== null) {
                $ids[] = $cid;
            }
        }
        return $ids;
    }

    /**
     * The effective active company for a non-admin request: the requested
     * `X-Act-As-Customer` company when the login belongs to it, else the primary
     * (`customer_id`) / first membership. Null when there is none.
     *
     * @param array<string,mixed> $claims
     */
    public static function resolve(array $claims, string $header): ?int
    {
        $allowed = self::allowedIds($claims);
        $requested = trim($header);
        if ($requested !== '' && ctype_digit($requested)) {
            $id = (int) $requested;
            if ($id > 0 && in_array($id, $allowed, true)) {
                return $id;
            }
        }
        return self::primaryId($claims) ?? $allowed[0] ?? null;
    }

    /**
     * The permissions the login holds within a given company. Reads the
     * `companies` claim; falls back to the flat `permissions` claim for
     * pre-multi-company tokens.
     *
     * @param array<string,mixed> $claims
     * @return list<string>
     */
    public static function permissionsFor(array $claims, ?int $companyId): array
    {
        $companies = self::companies($claims);
        if ($companyId !== null) {
            foreach ($companies as $c) {
                if ($c['id'] === $companyId) {
                    return $c['permissions'];
                }
            }
        }
        // A present-but-non-matching companies claim means "not a member of that
        // company" → no permissions. Only fall back to the flat `permissions`
        // claim for pre-multi-company tokens (no companies claim at all).
        if ($companies !== []) {
            return [];
        }
        if (isset($claims['permissions']) && is_array($claims['permissions'])) {
            return array_values(array_map('strval', $claims['permissions']));
        }
        return [];
    }

    /**
     * Normalise the `companies` claim (entries may be arrays or stdClass after
     * JWT decode) into a clean list.
     *
     * @param array<string,mixed> $claims
     * @return list<array{id:int, permissions:list<string>}>
     */
    private static function companies(array $claims): array
    {
        if (!isset($claims['companies']) || !is_array($claims['companies'])) {
            return [];
        }
        $out = [];
        foreach ($claims['companies'] as $entry) {
            $e = (array) $entry;
            $id = isset($e['id']) ? (int) $e['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $perms = isset($e['permissions']) && is_array($e['permissions'])
                ? array_values(array_map('strval', $e['permissions']))
                : [];
            $out[] = ['id' => $id, 'permissions' => $perms];
        }
        return $out;
    }
}
