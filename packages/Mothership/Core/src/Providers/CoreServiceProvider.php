<?php

namespace Mothership\Core\Providers;

use Elastic\Elasticsearch\Client as ElasticSearchClient;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Testing\Fakes\Fake;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Mothership\Core\CatalogScope;
use Mothership\Core\Console\Commands\MothershipPublish;
use Mothership\Core\Console\Commands\MothershipVersion;
use Mothership\Core\Console\Commands\TranslationsChecker;
use Mothership\Core\Contracts\Database\Grammar;
use Mothership\Core\Core;
use Mothership\Core\Database\ConnectionResolvers;
use Mothership\Core\Database\SqlServer\SqlServerConnection;
use Mothership\Core\ElasticSearch;
use Mothership\Core\Exceptions\Handler;
use Mothership\Core\Facades\Core as CoreFacade;
use Mothership\Core\Facades\ElasticSearch as ElasticSearchFacade;
use Mothership\Core\Helpers\Database\GrammarQueryManager;
use Mothership\Core\Helpers\Locales as LocalesHelper;
use Mothership\Core\Http\Middleware\EnableDebugForAllowedIps;
use Mothership\Core\Models\CoreConfig;
use Mothership\Core\Repositories\ChannelRepository;
use Mothership\Core\Repositories\LocaleRepository;
use Mothership\Core\RequestMemo;
use Mothership\Core\View\Compilers\BladeCompiler;
use Mothership\Theme\ViewRenderEventManager;

class CoreServiceProvider extends ServiceProvider
{
    const MAIL_CONFIGURED_KEY = 'mail.configured';

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Pin url()/asset()/Vite root + scheme to APP_URL so a poisoned Host header can't redirect asset URLs.
        if ($appUrl = config('app.url')) {
            URL::forceRootUrl($appUrl);

            if ($scheme = parse_url((string) $appUrl, PHP_URL_SCHEME)) {
                URL::forceScheme($scheme);
            }
        }

        include __DIR__.'/../Http/helpers.php';

        $purifierCachePath = storage_path('app/purifier');

        if (! is_dir($purifierCachePath)) {
            mkdir($purifierCachePath, 0755, true);
        }

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app->beforeResolving(MailManager::class, function (): void {
            $this->overrideMailConfiguration();
        });

        // A queue worker outlives the Email settings it resolved its mailer with: at each job boundary drop the
        // resolved mail manager (and the notification channels that hold it) so the next send re-reads the
        // settings. Nothing is read here; the settings load lazily when a job actually resolves the mailer.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->refreshMailForJob($event);
        });

        Event::listen('core.configuration.save.after', function (): void {
            app(RequestMemo::class)->forget(self::MAIL_CONFIGURED_KEY);
        });

        $this->app['router']->pushMiddlewareToGroup('web', EnableDebugForAllowedIps::class);

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'core');

        $this->publishes([
            dirname(__DIR__).'/Config/concord.php'       => config_path('concord.php'),
            dirname(__DIR__).'/Config/media.php'         => config_path('media.php'),
            dirname(__DIR__).'/Config/repository.php'    => config_path('repository.php'),
            dirname(__DIR__).'/Config/visitor.php'       => config_path('visitor.php'),
            dirname(__DIR__).'/Config/elasticsearch.php' => config_path('elasticsearch.php'),
        ]);

        $this->app->register(EventServiceProvider::class);

        $this->app->register(VisitorServiceProvider::class);

        $this->app->bind(ExceptionHandler::class, Handler::class);

        // Redirect unauthenticated web requests to admin login (default `login` route 500s); let API fall through to JSON 401.
        Authenticate::redirectUsing(
            fn ($request) => $request->is('api/*') ? null : route('admin.session.create')
        );

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'core');

        Event::listen('mothership.shop.layout.body.after', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('core::blade.tracer.style');
        });

        Event::listen('mothership.admin.layout.head', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('core::blade.tracer.style');
        });

        /**
         * Image Cache route
         */
        if (is_string(config('imagecache.route'))) {
            $filenamePattern = '[ \w\\.\\/\\-\\@\(\)\=]+';

            /**
             * Route to access template applied image file
             */
            $this->app['router']->get(config('imagecache.route').'/{template}/{filename}', [
                'uses' => 'Mothership\Core\ImageCache\Controller@getResponse',
                'as'   => 'imagecache',
            ])->where(['filename' => $filenamePattern]);
        }

        DB::macro('rawQueryGrammar', fn (): Grammar => GrammarQueryManager::getGrammar());

        // Drop the request-scoped config memo on any config write so later reads in the same request see it.
        $forgetConfigMemo = fn () => app(RequestMemo::class)->forget('core_config.');
        CoreConfig::saved($forgetConfigMemo);
        CoreConfig::deleted($forgetConfigMemo);
    }

    /**
     * Override the mail transport with admin Configuration (Email settings) values
     * when present. Deferred until a mailer is resolved: reading the settings costs
     * a schema check and several config queries, and almost no request sends mail.
     *
     * The request is only marked as configured once the settings were actually
     * readable. A mailer resolved before the connection is usable would otherwise
     * claim the flag and leave the rest of the request on the unconfigured
     * transport, so the admin's SMTP host applied only on some requests.
     */
    protected function refreshMailForJob(JobProcessing $event): void
    {
        // A sync job runs inside the request that dispatched it, which has already read the current settings.
        if ($event->connectionName === 'sync') {
            return;
        }

        $app = app();

        $app->make(RequestMemo::class)->forget(self::MAIL_CONFIGURED_KEY);
        $app->make(RequestMemo::class)->forget('core_config.');

        foreach (['mail.manager', ChannelManager::class] as $abstract) {
            // Never drop a test fake (Mail::fake(), Notification::fake()).
            if ($app->resolved($abstract) && ! $app->make($abstract) instanceof Fake) {
                $app->forgetInstance($abstract);
                Facade::clearResolvedInstance($abstract);
            }
        }
    }

    /**
     * The mail config as the environment set it, captured before the first override so that clearing the
     * Email settings can put it back.
     *
     * @var array<string, mixed>|null
     */
    private ?array $mailBaseline = null;

    protected function overrideMailConfiguration(): void
    {
        $memo = app(RequestMemo::class);

        if ($memo->has(self::MAIL_CONFIGURED_KEY)) {
            return;
        }

        try {
            if (! Schema::hasTable('core_config')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $memo->set(self::MAIL_CONFIGURED_KEY, true);

        $prefix = 'emails.configure.email_settings.';

        $this->mailBaseline ??= [
            'mail.default'      => config('mail.default'),
            'mail.mailers.smtp' => config('mail.mailers.smtp'),
            'mail.from'         => config('mail.from'),
        ];

        $host = core()->getConfigData($prefix.'mail_host');

        if (! $host) {
            // Settings cleared (or never set): the environment's transport, not the last override.
            config($this->mailBaseline);

            return;
        }

        // Start from the environment so a field cleared since the last override does not keep its old value.
        config($this->mailBaseline);

        $encryption = core()->getConfigData($prefix.'mail_encryption');

        config([
            'mail.default'                 => 'smtp',
            'mail.mailers.smtp.host'       => $host,
            'mail.mailers.smtp.port'       => core()->getConfigData($prefix.'mail_port') ?: config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.username'   => core()->getConfigData($prefix.'mail_username') ?: config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password'   => core()->getConfigData($prefix.'mail_password') ?: config('mail.mailers.smtp.password'),
            'mail.mailers.smtp.encryption' => ($encryption && $encryption !== 'none') ? $encryption : null,
        ]);

        if ($fromAddress = core()->getConfigData($prefix.'shop_email_from')) {
            config(['mail.from.address' => $fromAddress]);
        }

        if ($fromName = core()->getConfigData($prefix.'sender_name')) {
            config(['mail.from.name' => $fromName]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/media.php', 'media');

        // SQL Server only: a query builder that can insertOrIgnore(). Connections resolve
        // lazily, so registering here comes before the first sqlsrv connection.
        Connection::resolverFor(
            'sqlsrv',
            fn ($connection, $database, $prefix, $config) => new SqlServerConnection($connection, $database, $prefix, $config)
        );

        // Every driver: a failed COMMIT inside DB::transaction() rolls back instead of leaving PDO
        // in an open transaction that Laravel reports as level 0 (see RollsBackFailedCommits).
        ConnectionResolvers::register();

        $this->app->singleton('image_manager', function ($app): ImageManager {
            $driver = $app['config']->get('image.driver', 'gd');

            return match ($driver) {
                'imagick' => new ImageManager(new ImagickDriver),
                default   => new ImageManager(new GdDriver),
            };
        });

        $this->app->alias('image_manager', ImageManager::class);

        $this->registerFacades();

        $this->registerCommands();

        $this->registerBladeCompiler();
    }

    /**
     * Register Bouncer as a singleton.
     */
    protected function registerFacades(): void
    {
        $loader = AliasLoader::getInstance();

        $loader->alias('core', CoreFacade::class);

        $this->app->singleton('core', fn () => app()->make(Core::class));

        // Scoped, not singleton: a singleton would leak one admin's catalog scope into the next request under Octane.
        $this->app->scoped(CatalogScope::class, fn ($app): CatalogScope => new CatalogScope(
            $app->make(LocaleRepository::class),
            $app->make(ChannelRepository::class),
        ));

        $this->app->scoped(RequestMemo::class);

        // Scope this Locales subclass so it loads once per request; Astrotomic otherwise rebuilds it per translated attribute.
        $this->app->scoped(LocalesHelper::class);

        /**
         * Register ElasticSearch as a singleton.
         */
        $this->app->singleton('elasticsearch', fn (): ElasticSearch => new ElasticSearch);

        $loader->alias('elasticsearch', ElasticSearchFacade::class);

        $this->app->singleton(ElasticSearchClient::class, fn (): ElasticSearchClient => app()->make('elasticsearch')->connection());
    }

    /**
     * Register the console commands of this package.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TranslationsChecker::class,
                MothershipPublish::class,
                MothershipVersion::class,
            ]);
        }
    }

    /**
     * Register the Blade compiler implementation.
     */
    public function registerBladeCompiler(): void
    {
        $this->app->singleton('blade.compiler', fn ($app): BladeCompiler => new BladeCompiler($app['files'], $app['config']['view.compiled']));
    }
}
