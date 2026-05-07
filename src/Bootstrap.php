<?php
declare(strict_types=1);

namespace Tds\CustomerApi;

use DI\Container;
use Dotenv\Dotenv;
use GuzzleHttp\Client as GuzzleClient;
use PDO;
use Slim\App;
use Slim\Factory\AppFactory;
use Tds\CustomerApi\Action\Document\DownloadAction;
use Tds\CustomerApi\Action\Document\ListAction as DocumentListAction;
use Tds\CustomerApi\Action\Document\UploadAction;
use Tds\CustomerApi\Action\Invoice\ListAction as InvoiceListAction;
use Tds\CustomerApi\Action\Invoice\PayAction;
use Tds\CustomerApi\Action\Message\CreateAction as MessageCreateAction;
use Tds\CustomerApi\Action\Message\ListAction as MessageListAction;
use Tds\CustomerApi\Action\Project\GetAction as ProjectGetAction;
use Tds\CustomerApi\Action\Project\ListAction as ProjectListAction;
use Tds\CustomerApi\Action\Stripe\WebhookAction;
use Tds\CustomerApi\Infrastructure\Database;
use Tds\CustomerApi\Middleware\CorsMiddleware;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Service\JwksClient;

final class Bootstrap
{
    public static function createApp(string $rootDir): App
    {
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $container = new Container();

        $container->set(PDO::class, fn () => Database::connect([
            'host' => self::env('DB_HOST'),
            'port' => self::env('DB_PORT', '3306'),
            'name' => self::env('DB_NAME'),
            'user' => self::env('DB_USER'),
            'pass' => self::env('DB_PASS'),
        ]));

        $container->set(JwksClient::class, fn () => new JwksClient(
            http: new GuzzleClient(['timeout' => 5]),
            jwksUrl: self::env('AUTH_API_URL') . '/.well-known/jwks.json',
            cacheDir: $rootDir . '/var/cache',
            cacheTtl: (int) self::env('JWKS_CACHE_TTL', '600'),
        ));

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->add(new CorsMiddleware(self::corsOrigins()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::env('APP_ENV') !== 'production', true, true);

        $auth = new JwksAuthMiddleware($container->get(JwksClient::class));

        // Stripe webhook BYPASSES JwksAuthMiddleware because Stripe
        // can't authenticate with our JWT. Signature verification
        // happens inside the action handler.
        $app->post('/stripe/webhook', WebhookAction::class);

        // All other endpoints require a valid JWT
        $app->group('', function ($g) {
            $g->get('/projects', ProjectListAction::class);
            $g->get('/projects/{id:[0-9]+}', ProjectGetAction::class);
            $g->get('/invoices', InvoiceListAction::class);
            $g->post('/invoices/{id:[0-9]+}/pay', PayAction::class);
            $g->get('/documents', DocumentListAction::class);
            $g->post('/documents', UploadAction::class);
            $g->get('/documents/{id:[0-9]+}/download', DownloadAction::class);
            $g->get('/messages', MessageListAction::class);
            $g->post('/messages', MessageCreateAction::class);
        })->add($auth);

        return $app;
    }

    private static function env(string $key, ?string $default = null): string
    {
        $v = $_ENV[$key] ?? getenv($key) ?: $default;
        if ($v === null || $v === false) {
            throw new \RuntimeException("Missing required env var: {$key}");
        }
        return (string) $v;
    }

    /** @return string[] */
    private static function corsOrigins(): array
    {
        $raw = $_ENV['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS') ?: '';
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }
}
