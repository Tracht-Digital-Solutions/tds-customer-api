# Pitfalls

- **Don't put document paths under the webroot.** `$DOCUMENT_ROOT_DIR` must be outside `~/sites/`.
- **Keep Stripe calls in `WebhookAction` and `PayAction`.** Keep the surface small.
- **Never read `customer_id` from the request** (`_GET[customer_id]` or similar). Always
  `BaseAction::customerId()` from the JWT.
- **Env precedence trap.** `$_ENV[$key] ?? getenv($key) ?: $default` parses as
  `($_ENV[$key] ?? getenv($key)) ?: $default` and clobbers legitimately falsy values (`"0"`, `""`). Use
  explicit `?? false` checks. This hit all four APIs at once via copy-paste.
- **A required `self::env('FOO')` needs `FOO=` in `.env.example`** in the same change, or a copied
  example yields a non-booting app.
- **Keep `Access-Control-Allow-Methods` in step with the router.** Don't widen it beyond routed methods,
  and add a new method (e.g. `PATCH`, `PUT`, `DELETE`) in the same commit as its route.
- **Add `CorsMiddleware` after `addRoutingMiddleware()`.** Slim middleware is LIFO (last added runs
  first). Added earlier, routing 405s every OPTIONS preflight before CORS can answer, and browsers block
  every cross-origin request. `tests/PreflightTest.php` sends an OPTIONS request through the real
  `Bootstrap::createApp()` as the guard.
- **Run `php -S` with `public/router.php`** (`composer start` does). Without it the built-in server 404s
  any dotted path without a file, the JWKS fetch breaks and every endpoint 401s. Apache (`.htaccess`)
  and the gateway's in-process mode don't need it.
- **Never compare `NOW()` columns with UTC values** in one condition (see [database.md](database.md)).
