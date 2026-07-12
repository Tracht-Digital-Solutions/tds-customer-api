<?php
declare(strict_types=1);

namespace Tds\CustomerApi;

use DI\Container;
use Dotenv\Dotenv;
use GuzzleHttp\Client as GuzzleClient;
use PDO;
use Slim\App;
use Slim\Factory\AppFactory;
use Tds\CustomerApi\Action\Account\CompaniesAction;
use Tds\CustomerApi\Action\Account\GetMeAction;
use Tds\CustomerApi\Action\Account\UpdateMeAction;
use Tds\CustomerApi\Action\Admin\CreateCustomerAction;
use Tds\CustomerApi\Action\Admin\ListCustomersAction as AdminListCustomersAction;
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
use Tds\CustomerApi\Action\Ticket\AttachmentDownloadAction as TicketAttachmentDownloadAction;
use Tds\CustomerApi\Action\Ticket\AttachmentUploadAction as TicketAttachmentUploadAction;
use Tds\CustomerApi\Action\Ticket\CommentAction as TicketCommentAction;
use Tds\CustomerApi\Action\Ticket\ContactIngestAction as TicketContactIngestAction;
use Tds\CustomerApi\Action\Ticket\CreateAction as TicketCreateAction;
use Tds\CustomerApi\Action\Ticket\GetAction as TicketGetAction;
use Tds\CustomerApi\Action\Ticket\IngestAction as TicketIngestAction;
use Tds\CustomerApi\Action\Ticket\ListAction as TicketListAction;
use Tds\CustomerApi\Action\Admin\Ticket\AttachmentDownloadAction as AdminTicketAttachmentDownloadAction;
use Tds\CustomerApi\Action\Admin\Ticket\CommentAction as AdminTicketCommentAction;
use Tds\CustomerApi\Action\Admin\Ticket\GetAction as AdminTicketGetAction;
use Tds\CustomerApi\Action\Admin\Ticket\ImapTestAction as AdminTicketImapTestAction;
use Tds\CustomerApi\Action\Admin\Ticket\IngestAction as AdminTicketIngestAction;
use Tds\CustomerApi\Action\Admin\Ticket\ListAction as AdminTicketListAction;
use Tds\CustomerApi\Action\Admin\Ticket\UpdateAction as AdminTicketUpdateAction;
use Tds\CustomerApi\Action\Admin\TicketStatus\CreateAction as TicketStatusCreateAction;
use Tds\CustomerApi\Action\Admin\TicketStatus\DeleteAction as TicketStatusDeleteAction;
use Tds\CustomerApi\Action\Admin\TicketStatus\ListAction as TicketStatusListAction;
use Tds\CustomerApi\Action\Admin\TicketStatus\UpdateAction as TicketStatusUpdateAction;
use Tds\CustomerApi\Action\Admin\TicketSettings\GetAction as TicketSettingsGetAction;
use Tds\CustomerApi\Action\Admin\TicketSettings\PutAction as TicketSettingsPutAction;
use Tds\CustomerApi\Action\Admin\AppSettings\GetAction as AppSettingsGetAction;
use Tds\CustomerApi\Action\Admin\AppSettings\PutAction as AppSettingsPutAction;
use Tds\CustomerApi\Action\TimeEntry\ListAction as TimeEntryListAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\CreateAction as AdminTimeEntryCreateAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\DeleteAction as AdminTimeEntryDeleteAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\ExportLexwareAction as AdminTimeEntryExportLexwareAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\ListAction as AdminTimeEntryListAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\TimerCurrentAction as AdminTimerCurrentAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\TimerStartAction as AdminTimerStartAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\TimerStopAction as AdminTimerStopAction;
use Tds\CustomerApi\Action\Admin\TimeEntry\UpdateAction as AdminTimeEntryUpdateAction;
use Tds\CustomerApi\Infrastructure\Database;
use Tds\CustomerApi\Middleware\AuditLogMiddleware;
use Tds\CustomerApi\Middleware\CorsMiddleware;
use Tds\CustomerApi\Middleware\JwksAuthMiddleware;
use Tds\CustomerApi\Middleware\RequirePermissionMiddleware;
use Tds\CustomerApi\Service\AppSettings;
use Tds\CustomerApi\Service\AttachmentStorage;
use Tds\CustomerApi\Service\DocumentSigner;
use Tds\CustomerApi\Service\ImapTicketIngest;
use Tds\CustomerApi\Service\JwksClient;
use Tds\CustomerApi\Service\LexwareClient;
use Tds\CustomerApi\Service\LexwareInvoiceBuilder;
use Tds\CustomerApi\Service\SmtpMailer;
use Tds\CustomerApi\Service\TicketMailer;
use Tds\CustomerApi\Service\TicketRepository;
use Tds\CustomerApi\Service\TicketSettings;
use Tds\CustomerApi\Service\TicketStatusRepository;
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

        // Runtime service-config store (Stripe / ticket mailer / Lexware),
        // DB-first with .env fallback. Factory only — resolved lazily by the
        // consumers below and the /admin/settings actions, so boot stays
        // DB-free.
        $container->set(AppSettings::class, fn (Container $c) => new AppSettings(
            $c->get(PDO::class),
            self::env('SETTINGS_ENCRYPTION_KEY', ''),
        ));

        // Health probe resolves PDO + settings lazily (inside its own
        // try/catch) so a DB/config outage reports `db: down` with HTTP 200
        // instead of 5xx'ing during construction.
        $container->set(HealthAction::class, fn (Container $c) => new HealthAction(
            static fn (): PDO => $c->get(PDO::class),
            static fn (): AppSettings => $c->get(AppSettings::class),
        ));

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

        // Resolved lazily (added by class name below) so it never opens a DB
        // connection at boot — only when an authenticated route actually runs.
        // Keeps `/healthz` (and app construction) green during a DB outage.
        $container->set(AuditLogMiddleware::class, fn (Container $c) => new AuditLogMiddleware(
            $c->get(PDO::class),
        ));

        // Lexware Office invoice export from the time tracker. The API key
        // is optional — when unset the export endpoint returns 503 and the
        // admin UI shows the feature as unconfigured.
        // Lexware config now comes from the AppSettings store (DB-first, .env
        // fallback). Factories are lazy, so the settings lookup only opens a DB
        // connection when the export endpoint actually runs — boot stays green.
        $container->set(LexwareInvoiceBuilder::class, fn () => new LexwareInvoiceBuilder());
        $container->set(LexwareClient::class, fn (Container $c) => new LexwareClient(
            http: new GuzzleClient(),
            apiKey: $c->get(AppSettings::class)->get('LEXWARE_API_KEY'),
            baseUrl: $c->get(AppSettings::class)->get('LEXWARE_API_URL'),
        ));
        $container->set(AdminTimeEntryExportLexwareAction::class, fn (Container $c) => new AdminTimeEntryExportLexwareAction(
            pdo: $c->get(PDO::class),
            lexware: $c->get(LexwareClient::class),
            builder: $c->get(LexwareInvoiceBuilder::class),
            defaultHourlyRate: (float) $c->get(AppSettings::class)->get('LEXWARE_DEFAULT_HOURLY_RATE'),
            defaultTaxRate: (float) $c->get(AppSettings::class)->get('LEXWARE_TAX_RATE_PERCENT'),
        ));

        // SMTP transport for ticket notifications. Credentials come from the
        // AppSettings store (DB-first, .env fallback). Sends over stream sockets
        // (no proc_open), so it works in-process under the gateway. Lazy factory.
        $container->set(SmtpMailer::class, fn (Container $c) => new SmtpMailer(
            host: $c->get(AppSettings::class)->get('SMTP_HOST'),
            port: $c->get(AppSettings::class)->get('SMTP_PORT'),
            user: $c->get(AppSettings::class)->get('SMTP_USER'),
            pass: $c->get(AppSettings::class)->get('SMTP_PASSWORD'),
            security: $c->get(AppSettings::class)->get('SMTP_SECURITY'),
            from: $c->get(AppSettings::class)->get('SMTP_FROM'),
        ));

        // Ticket notification mailer (SMTP). Optional — no-ops when SMTP is
        // unconfigured, and each event is additionally gated by the
        // ticket_setting toggles, so the whole feature degrades to in-app only.
        // Customer-facing mails set Reply-To = TICKET_INBOX_ADDRESS (the
        // IMAP-monitored inbox) so replies thread back via the ingester.
        $container->set(TicketMailer::class, fn (Container $c) => new TicketMailer(
            mailer: $c->get(SmtpMailer::class),
            adminTo: $c->get(AppSettings::class)->get('TICKET_ADMIN_EMAIL'),
            inboxAddress: $c->get(AppSettings::class)->get('TICKET_INBOX_ADDRESS'),
            adminAppUrl: self::env('ADMIN_APP_URL', 'https://management.tracht-digital.de'),
            customerAppUrl: self::env('CUSTOMER_APP_URL', 'https://app.tracht-digital.de'),
        ));

        // Inbound IMAP → ticket ingester. Config (mailbox + folder) from the
        // AppSettings store; the repos/mailer are autowired concretes. Lazy
        // factory → the mailbox is only opened when poll()/testConnection() runs,
        // never at boot. no-ops when IMAP is unconfigured.
        $container->set(ImapTicketIngest::class, fn (Container $c) => new ImapTicketIngest(
            pdo: $c->get(PDO::class),
            tickets: $c->get(TicketRepository::class),
            statuses: $c->get(TicketStatusRepository::class),
            attachments: $c->get(AttachmentStorage::class),
            mailer: $c->get(TicketMailer::class),
            settings: $c->get(TicketSettings::class),
            host: $c->get(AppSettings::class)->get('IMAP_HOST'),
            port: $c->get(AppSettings::class)->get('IMAP_PORT'),
            user: $c->get(AppSettings::class)->get('IMAP_USER'),
            pass: $c->get(AppSettings::class)->get('IMAP_PASSWORD'),
            security: $c->get(AppSettings::class)->get('IMAP_SECURITY'),
            folder: $c->get(AppSettings::class)->get('IMAP_FOLDER'),
        ));

        $container->set(CreateCustomerAction::class, fn (Container $c) => new CreateCustomerAction(
            pdo: $c->get(PDO::class),
            http: new GuzzleClient(['timeout' => 10, 'connect_timeout' => 5]),
            authApiUrl: self::env('AUTH_API_URL'),
            // Server-to-server token for the auth-api onboarding call. Falls
            // back to the legacy ADMIN_TOKEN until SERVICE_TOKEN is set.
            serviceToken: self::env('SERVICE_TOKEN', self::env('ADMIN_TOKEN', '')),
        ));

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::env('APP_ENV') !== 'production', true, true);
        // Slim middleware is LIFO — the LAST added runs FIRST. CORS must be
        // added after routing/error so it is outermost: otherwise the routing
        // middleware 405s an OPTIONS preflight (no OPTIONS routes are
        // registered) before CorsMiddleware can short-circuit it, and the
        // browser blocks every cross-origin JSON/Authorization request.
        $app->add(new CorsMiddleware(self::corsOrigins()));

        $auth = new JwksAuthMiddleware($container->get(JwksClient::class));
        $adminJwt = new JwksAuthMiddleware($container->get(JwksClient::class), requireAdmin: true);

        $perm = static fn (string $p) => new RequirePermissionMiddleware($p);

        // Public endpoints — bypass auth
        $app->get('/healthz', HealthAction::class);
        // Stripe webhook authenticates via Stripe-Signature header
        // (verified inside the action), so no JWT required.
        $app->post('/stripe/webhook', WebhookAction::class);
        // IMAP → ticket ingest, driven by an external scheduler (no cron/CLI on
        // the prod host). Authenticates via the INGEST_TOKEN secret verified
        // inside the action, so no JWT required.
        $app->post('/tickets/ingest', TicketIngestAction::class);
        // Contact-form → ticket ingest, called server-to-server by
        // tds-contact-api on each submission. Same INGEST_TOKEN secret auth.
        $app->post('/tickets/contact', TicketContactIngestAction::class);
        // Signed-URL download authenticates via the URL's HMAC. The
        // signature IS the auth — verified inside the action.
        $app->get('/documents/sign', SignedDownloadAction::class);

        // Admin endpoints — per-admin JWT (admin=true claim), verified via
        // JWKS. Replaces the old shared ADMIN_TOKEN gate.
        $app->post('/admin/customers', CreateCustomerAction::class)->add($adminJwt);
        $app->get('/admin/customers', AdminListCustomersAction::class)->add($adminJwt);
        $app->get('/admin/projects', AdminListProjectsAction::class)->add($adminJwt);

        // Ticket administration (triage board, status registry, notifications).
        $app->group('/admin/tickets', function ($g) {
            $g->get('', AdminTicketListAction::class);
            // Literal segments before the {id} routes — non-numeric, so no clash.
            $g->post('/ingest', AdminTicketIngestAction::class);
            $g->get('/imap-test', AdminTicketImapTestAction::class);
            $g->get('/{id:[0-9]+}', AdminTicketGetAction::class);
            $g->patch('/{id:[0-9]+}', AdminTicketUpdateAction::class);
            $g->post('/{id:[0-9]+}/comments', AdminTicketCommentAction::class);
            $g->get('/{id:[0-9]+}/attachments/{aid:[0-9]+}', AdminTicketAttachmentDownloadAction::class);
        })->add($adminJwt);

        $app->group('/admin/ticket-statuses', function ($g) {
            $g->get('', TicketStatusListAction::class);
            $g->post('', TicketStatusCreateAction::class);
            $g->patch('/{id:[0-9]+}', TicketStatusUpdateAction::class);
            $g->delete('/{id:[0-9]+}', TicketStatusDeleteAction::class);
        })->add($adminJwt);

        $app->get('/admin/ticket-settings', TicketSettingsGetAction::class)->add($adminJwt);
        $app->put('/admin/ticket-settings', TicketSettingsPutAction::class)->add($adminJwt);

        // Runtime service config (Stripe / ticket mailer / Lexware), edited via
        // the Einrichtungsassistent + Einstellungen in tds-admin.
        $app->get('/admin/settings', AppSettingsGetAction::class)->add($adminJwt);
        $app->put('/admin/settings', AppSettingsPutAction::class)->add($adminJwt);

        $app->group('/admin/time-entries', function ($g) {
            $g->get('', AdminTimeEntryListAction::class);
            $g->post('', AdminTimeEntryCreateAction::class);
            $g->get('/timer', AdminTimerCurrentAction::class);
            $g->post('/timer/start', AdminTimerStartAction::class);
            $g->post('/timer/stop', AdminTimerStopAction::class);
            $g->post('/export-lexware', AdminTimeEntryExportLexwareAction::class);
            $g->patch('/{id:[0-9]+}', AdminTimeEntryUpdateAction::class);
            $g->delete('/{id:[0-9]+}', AdminTimeEntryDeleteAction::class);
        })->add($adminJwt);

        // All other endpoints require a valid JWT. AuditLog runs inside the
        // auth group so every authenticated request is recorded with the JWT
        // claims attached. Each portal action is additionally gated by the
        // permission its company account must hold (admins bypass).
        $app->group('', function ($g) use ($perm) {
            $g->get('/me', GetMeAction::class);
            $g->get('/me/companies', CompaniesAction::class);
            $g->patch('/me', UpdateMeAction::class);
            $g->get('/projects', ProjectListAction::class)->add($perm('projects:read'));
            $g->get('/projects/{id:[0-9]+}', ProjectGetAction::class)->add($perm('projects:read'));
            $g->get('/projects/{id:[0-9]+}/time-entries', TimeEntryListAction::class)->add($perm('projects:read'));
            $g->get('/invoices', InvoiceListAction::class)->add($perm('invoices:read'));
            $g->post('/invoices/{id:[0-9]+}/pay', PayAction::class)->add($perm('invoices:pay'));
            $g->get('/documents', DocumentListAction::class)->add($perm('documents:read'));
            $g->post('/documents', UploadAction::class)->add($perm('documents:write'));
            $g->patch('/documents/{id:[0-9]+}', DocumentRenameAction::class)->add($perm('documents:write'));
            $g->get('/documents/{id:[0-9]+}/download', DownloadAction::class)->add($perm('documents:read'));
            // /sign mints a short-lived signed download URL — part of the read
            // path, not an e-signature, so it requires documents:read.
            $g->post('/documents/{id:[0-9]+}/sign', SignAction::class)->add($perm('documents:read'));
            $g->get('/messages', MessageListAction::class)->add($perm('messages:read'));
            $g->post('/messages', MessageCreateAction::class)->add($perm('messages:write'));
            $g->patch('/messages/{id:[0-9]+}', MessageUpdateAction::class)->add($perm('messages:write'));
            $g->get('/tickets', TicketListAction::class)->add($perm('tickets:read'));
            $g->post('/tickets', TicketCreateAction::class)->add($perm('tickets:write'));
            $g->get('/tickets/{id:[0-9]+}', TicketGetAction::class)->add($perm('tickets:read'));
            $g->post('/tickets/{id:[0-9]+}/comments', TicketCommentAction::class)->add($perm('tickets:write'));
            $g->post('/tickets/{id:[0-9]+}/attachments', TicketAttachmentUploadAction::class)->add($perm('tickets:write'));
            $g->get('/tickets/{id:[0-9]+}/attachments/{aid:[0-9]+}', TicketAttachmentDownloadAction::class)->add($perm('tickets:read'));
        })->add(AuditLogMiddleware::class)->add($auth);

        return $app;
    }

    /**
     * Env reader. NB: explicit `?? false` checks — never
     * `$_ENV[$key] ?? getenv($key) ?: $default`, which clobbers falsy
     * values ("0", "") because `??` binds tighter than `?:` (the bug
     * that bit all four APIs via copy-paste).
     */
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
