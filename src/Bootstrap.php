<?php
declare(strict_types=1);

namespace Tds\CustomerApi;

use DI\Container;
use Dotenv\Dotenv;
use GuzzleHttp\Client as GuzzleClient;
use PDO;
use Slim\App;
use Slim\Factory\AppFactory;
use Tds\CustomerApi\Action\Account\GetMeAction;
use Tds\CustomerApi\Action\Account\UpdateMeAction;
use Tds\CustomerApi\Action\Admin\CreateCustomerAction;
use Tds\CustomerApi\Action\Admin\ListProjectsAction as AdminListProjectsAction;
use Tds\CustomerApi\Action\Document\DownloadAction;
use Tds\CustomerApi\Action\Document\ListAction as DocumentListAction;
use Tds\CustomerApi\Action\Document\RenameAction as DocumentRenameAction;
use Tds\CustomerApi\Action\Document\SignAction;
use Tds\CustomerApi\Action\Document\SignedDownloadAction;
use Tds\CustomerApi\Action\Document\UploadAction;
use Tds\CustomerApi\Action\HealthAction;
use Tds\CustomerApi\Action\Invoice\ListAction as InvoiceListAction;
use Tds\CustomerApi\Action\Invoice\PayAction;
use Tds\CustomerApi\Action\Message\CreateAction as MessageCreateAction;
use Tds\CustomerApi\Action\Message\ListAction as MessageListAction;
use Tds\CustomerApi\Action\Message\UpdateAction as MessageUpdateAction;
use Tds\CustomerApi\Action\Project\GetAction as ProjectGetAction;
use Tds\CustomerApi\Action\Project\ListAction as ProjectListAction;
use Tds\CustomerApi\Action\Stripe\WebhookAction;
use Tds\CustomerApi\Action\TimeEntry\ListAction as TimeEntryListAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\CreateAction as AdminTimeEntryCreateAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\DeleteAction as AdminTimeEntryDeleteAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\ListAction as AdminTimeEntryListAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\TimerCurrentAction as AdminTimerCurrentAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\TimerStartAction as AdminTimerStartAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\TimerStopAction as AdminTimerStopAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\UpdateAction as AdminTimeEntryUpdateAction;
use Tds\CustomerApi\Infrastructure\Database;
use Tds\CustomerApi\Middleware\AdminAuthMiddleware;
use Tds\CustomerApi\Middleware\AuditLogMiddleware;
use Tds\CustomerApi\Middleware\CorsMiddleware;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Service\DocumentSigner;
use Tds\CustomerApi\Service\JwksClient;
use Tds\CustomerApi\Service\TimeEntryRepository;

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

        $container->set(DocumentSigner::class, fn () => new DocumentSigner(
            self::env('DOCUMENT_SIGN_SECRET'),
        ));

        $container->set(TimeEntryRepository::class, fn (Container $c) => new TimeEntryRepository(
            $c->get(PDO::class),
        ));

        $container->set(CreateCustomerAction::class, fn (Container $c) => new CreateCustomerAction(
            pdo: $c->get(PDO::class),
            http: new GuzzleClient(['timeout' => 10, 'connect_timeout' => 5]),
            authApiUrl: self::env('AUTH_API_URL'),
            adminToken: self::env('ADMIN_TOKEN'),
        ));

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->add(new CorsMiddleware(self::corsOrigins()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::env('APP_ENV') !== 'production', true, true);

        $auth = new JwksAuthMiddleware($container->get(JwksClient::class));
        $audit = new AuditLogMiddleware($container->get(PDO::class));
        $admin = new AdminAuthMiddleware(self::env('ADMIN_TOKEN', ''));

        // Public endpoints — bypass auth
        $app->get('/healthz', HealthAction::class);
        // Stripe webhook authenticates via Stripe-Signature header
        // (verified inside the action), so no JWT required.
        $app->post('/stripe/webhook', WebhookAction::class);
        // Signed-URL download authenticates via the URL's HMAC. The
        // signature IS the auth — verified inside the action.
        $app->get('/documents/sign', SignedDownloadAction::class);

        // Admin endpoints — Bearer ADMIN_TOKEN. Not behind JwksAuth
        // because admin tooling carries the shared token, not a JWT.
        $app->post('/admin/customers', CreateCustomerAction::class)->add($admin);
        $app->get('/admin/projects', AdminListProjectsAction::class)->add($admin);

        $app->group('/admin/time-entries', function ($g) {
            $g->get('', AdminTimeEntryListAction::class);
            $g->post('', AdminTimeEntryCreateAction::class);
            $g->get('/timer', AdminTimerCurrentAction::class);
            $g->post('/timer/start', AdminTimerStartAction::class);
            $g->post('/timer/stop', AdminTimerStopAction::class);
            $g->patch('/{id:[0-9]+}', AdminTimeEntryUpdateAction::class);
            $g->delete('/{id:[0-9]+}', AdminTimeEntryDeleteAction::class);
        })->add($admin);

        // All other endpoints require a valid JWT. AuditLog runs
        // inside the auth group so every authenticated request is
        // recorded with the JWT claims attached.
        $app->group('', function ($g) {
            $g->get('/me', GetMeAction::class);
            $g->patch('/me', UpdateMeAction::class);
            $g->get('/projects', ProjectListAction::class);
            $g->get('/projects/{id:[0-9]+}', ProjectGetAction::class);
            $g->get('/projects/{id:[0-9]+}/time-entries', TimeEntryListAction::class);
            $g->get('/invoices', InvoiceListAction::class);
            $g->post('/invoices/{id:[0-9]+}/pay', PayAction::class);
            $g->get('/documents', DocumentListAction::class);
            $g->post('/documents', UploadAction::class);
            $g->patch('/documents/{id:[0-9]+}', DocumentRenameAction::class);
            $g->get('/documents/{id:[0-9]+}/download', DownloadAction::class);
            $g->post('/documents/{id:[0-9]+}/sign', SignAction::class);
            $g->get('/messages', MessageListAction::class);
            $g->post('/messages', MessageCreateAction::class);
            $g->patch('/messages/{id:[0-9]+}', MessageUpdateAction::class);
        })->add($audit)->add($auth);

        return $app;
    }

    private static function env(string $key, ?string $default = null): string
    {
        $v = $_ENV[$key] ?? false;
        if ($v === false) {
            $v = getenv($key);
        }
        if ($v === false) {
            $v = $default;
        }
        if ($v === null) {
            throw new \RuntimeException("Missing required env var: {$key}");
        }
        return (string) $v;
    }

    /** @return string[] */
    private static function corsOrigins(): array
    {
        $raw = $_ENV['CORS_ALLOWED_ORIGINS'] ?? false;
        if ($raw === false) {
            $raw = getenv('CORS_ALLOWED_ORIGINS');
        }
        if ($raw === false) {
            $raw = '';
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }
}
