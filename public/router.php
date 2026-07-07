<?php
declare(strict_types=1);

// Router script for PHP's built-in server (`php -S … public/router.php`).
//
// Without it, `php -S` serves its own 404 for any path whose last segment
// contains a dot and doesn't exist on disk — /.well-known/jwks.json never
// reaches Slim. Apache (.htaccess) and the gateway's in-process mode don't
// need this; it is only for `composer start` and the proxy-mode stack.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . $path);
if ($file !== false && is_file($file) && str_starts_with($file, __DIR__)) {
    return false; // real file (e.g. install.php) — let php -S serve it
}

require __DIR__ . '/index.php';
