<?php

declare(strict_types=1);

namespace Tds\CustomerApi\Support;

/**
 * One way to read a configuration value: `$_ENV` first, then `getenv()`.
 *
 * `Bootstrap` loads `.env` with phpdotenv's IMMUTABLE repository, which fills
 * `$_ENV`/`$_SERVER` but never calls `putenv()` — and the gateway's in-process
 * dispatcher additionally clears every key the service's `.env` declares from
 * the process environment. So `getenv('DOCUMENT_ROOT_DIR')` was always empty
 * on the host: uploads answered 503, downloads 410, and `/healthz` reported
 * blob storage as unconfigured, although the installer had written the value.
 *
 * Note the explicit `=== false` checks: `$_ENV[$k] ?? getenv($k) ?: $d` binds
 * as `($_ENV[$k] ?? getenv($k)) ?: $d` and clobbers "0" and "".
 */
final class Env
{
    public static function get(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
        if ($value === false) {
            $value = getenv($key);
        }
        return $value === false ? $default : (string) $value;
    }
}
