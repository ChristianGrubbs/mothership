<?php

namespace Mothership\AdminApi\Providers;

use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\UserRepository as PassportUserRepository;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Mothership\Admin\Http\Middleware\EnsurePimEnabled;
use Mothership\AdminApi\Cache\StructureCache;
use Mothership\AdminApi\Console\ApiClientCommand;
use Mothership\AdminApi\Console\PassportKeysCommand;
use Mothership\AdminApi\Http\Middleware\DeprecatedRoute;
use Mothership\AdminApi\Http\Middleware\EnsureAcceptsJson;
use Mothership\AdminApi\Http\Middleware\LocaleMiddleware;
use Mothership\AdminApi\Http\Middleware\ScopeMiddleware;
use Mothership\AdminApi\Models\Client;
use Mothership\AdminApi\Repositories\UserRepository;
use Mothership\AdminApi\Services\OauthKeyStore;
use Mothership\Attribute\Models\Attribute;
use Mothership\Attribute\Models\AttributeFamily;
use Mothership\Attribute\Models\AttributeFamilyGroupMapping;
use Mothership\Attribute\Models\AttributeGroup;
use Mothership\Attribute\Models\AttributeGroupTranslation;
use Mothership\Attribute\Models\AttributeOption;
use Mothership\Attribute\Models\AttributeOptionTranslation;
use Mothership\Attribute\Models\AttributeTranslation;
use Mothership\Category\Models\CategoryField;
use Mothership\Category\Models\CategoryFieldOption;
use Mothership\Category\Models\CategoryFieldOptionTranslation;
use Mothership\Category\Models\CategoryFieldTranslation;
use Mothership\Core\Models\Channel;
use Mothership\Core\Models\ChannelTranslation;
use Mothership\Core\Models\Currency;
use Mothership\Core\Models\Locale;
use Mothership\Core\Tree;
use Mothership\User\Models\Admin;

class AdminApiServiceProvider extends ServiceProvider
{
    /**
     * Register your middleware aliases here.
     *
     * @var array
     */
    protected $middlewareAliases = [
        'accept.json'    => EnsureAcceptsJson::class,
        'request.locale' => LocaleMiddleware::class,
        'api.scope'      => ScopeMiddleware::class,
        'api.deprecated' => DeprecatedRoute::class,
        'pim.enabled'    => EnsurePimEnabled::class,
    ];

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Route::middleware('web')
            ->where(['id' => '[0-9]+'])
            ->group(__DIR__.'/../Routes/integrations-routes.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->activateMiddlewareAliases();
        $this->registerRateLimiter();
        $this->registerPassportTokenRoutes();

        // The dummy hash an ownerless client is checked against is made after the token response is sent, so
        // no credential check pays for hashing (UserRepository::dummyHash).
        $this->app->terminating(function () {
            if (request()->routeIs('passport.token')) {
                $this->app->make(PassportUserRepository::class)->warmDummyHash();
            }
        });
        $this->throttleTokenRoutes();
        $this->activatePassportApiClient();

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'admin_api');
        $this->composeView();
        $this->registerACL();
        $this->registerStructureCacheInvalidation();
    }

    /**
     * Rotate the structure-response cache whenever a structure entity is
     * written through Eloquent, and wholesale after any import completes
     * (imports may write structure rows below the model layer).
     */
    protected function registerStructureCacheInvalidation(): void
    {
        $modelGroups = [
            Attribute::class                          => ['attributes', 'families'],
            AttributeTranslation::class               => ['attributes'],
            AttributeOption::class                    => ['attributes'],
            AttributeOptionTranslation::class         => ['attributes'],
            AttributeGroup::class                     => ['attribute_groups', 'families'],
            AttributeGroupTranslation::class          => ['attribute_groups', 'families'],
            AttributeFamily::class                    => ['families'],
            AttributeFamilyGroupMapping::class        => ['families'],
            CategoryField::class                      => ['category_fields'],
            CategoryFieldTranslation::class           => ['category_fields'],
            CategoryFieldOption::class                => ['category_fields'],
            CategoryFieldOptionTranslation::class     => ['category_fields'],
            Channel::class                            => ['channels'],
            ChannelTranslation::class                 => ['channels'],
            Locale::class                             => ['locales', 'channels'],
            Currency::class                           => ['currencies', 'channels'],
        ];

        foreach ($modelGroups as $model => $groups) {
            foreach (['saved', 'deleted'] as $modelEvent) {
                $model::{$modelEvent}(static fn () => app(StructureCache::class)->bump(...$groups));
            }
        }

        Event::listen('data_transfer.imports.completed', static fn () => app(StructureCache::class)->bumpAll());

        // Channel-derived activation flips status with a mass update, which fires no model events.
        Event::listen('core.locale.activation.synced', static fn () => app(StructureCache::class)->bump('locales', 'channels'));
        Event::listen('core.currency.activation.synced', static fn () => app(StructureCache::class)->bump('currencies', 'channels'));
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerCommands();
        $this->registerApiRoutes();
        $this->registerConfig();

        // Passport's route file also publishes the browser authorization-code and device-code
        // screens. Neither grant is configured here (no authorization view, no device flow), so
        // those URLs could only answer 500. Only the two token endpoints the API uses are mounted.
        Passport::ignoreRoutes();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/api.php', 'api'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/api-acl.php', 'api-acl'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php', 'menu.admin'
        );
    }

    /**
     * Register the rate limiter guarding the REST API.
     */
    protected function registerRateLimiter(): void
    {
        RateLimiter::for('rest-api', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limit', 120))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('oauth-token', function (Request $request) {
            return Limit::perMinute((int) config('api.token_rate_limit', 10))
                ->by(((string) $request->input('username')).'|'.$request->ip());
        });
    }

    /**
     * Passport auto-registers its OAuth routes with no rate limit. Append a
     * throttle to the token endpoints to blunt password-grant brute force
     * without forking Passport's route definitions.
     */
    /**
     * Mount the password-grant token endpoint and the transient-token refresh, matching
     * Passport's own definitions (vendor/laravel/passport/routes/web.php) for these two routes.
     */
    protected function registerPassportTokenRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'as'         => 'passport.',
            'prefix'     => config('passport.path', 'oauth'),
            'namespace'  => 'Laravel\\Passport\\Http\\Controllers',
            'middleware' => config('passport.middleware', []),
        ], function (): void {
            Route::post('/token', [
                'uses'       => 'AccessTokenController@issueToken',
                'as'         => 'token',
                'middleware' => 'throttle',
            ]);

            $guard = config('passport.guard');

            Route::post('/token/refresh', [
                'uses'       => 'TransientTokenController@refresh',
                'as'         => 'token.refresh',
                'middleware' => ['web', $guard ? 'auth:'.$guard : 'auth'],
            ]);
        });
    }

    protected function throttleTokenRoutes(): void
    {
        $this->app->booted(function (): void {
            $routes = $this->app['router']->getRoutes();

            foreach (['passport.token', 'passport.token.refresh'] as $name) {
                $routes->getByName($name)?->middleware('throttle:oauth-token');
            }
        });
    }

    /**
     * Define the "api" routes for the application.
     *
     * @return void
     */
    protected function registerApiRoutes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/admin-api.php');

    }

    /**
     * Register the Installer Commands of this package.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ApiClientCommand::class,
                PassportKeysCommand::class,
            ]);
        }
    }

    /**
     * Configures Passport for the admin API.
     *
     * Keys are created by mothership:passport:keys rather than here, so a request
     * never writes to disk and containers booting in parallel cannot race each
     * other into a mismatched pair. Their permissions vary by host umask, so
     * league/oauth2-server v9's 600/660 check is skipped to keep the API
     * bootable.
     */
    protected function activatePassportApiClient(): void
    {
        Passport::loadKeysFrom($this->app->make(OauthKeyStore::class)->resolvedPath());

        Passport::$validateKeyPermissions = false;

        Passport::$passwordGrantEnabled = true;
        Passport::useClientModel(Client::class);

        // Register a custom UserRepository that uses the Admin model instead of App\Models\User
        // This ensures that Passport's OAuth2 password grant correctly authenticates admin users
        $this->app->singleton(PassportUserRepository::class, function ($app) {
            return new UserRepository($app->make('hash'));
        });

        $accessTokenTtl = (int) config('api.access_token_ttl', 3600);
        $refreshTokenTtl = (int) config('api.refresh_token_ttl', 2592000);

        // Relative intervals keep expiry Octane-safe (absolute now() would freeze at worker boot).
        Passport::tokensExpireIn(CarbonInterval::seconds($accessTokenTtl));

        Passport::refreshTokensExpireIn(CarbonInterval::seconds($refreshTokenTtl));

        $this->app->bind(ClientRepository::class, \Mothership\AdminApi\Repositories\ClientRepository::class);
    }

    /**
     * Activate middleware aliases.
     *
     * @return void
     */
    protected function activateMiddlewareAliases()
    {
        collect($this->middlewareAliases)->each(function ($className, $alias) {
            $this->app['router']->aliasMiddleware($alias, $className);
        });
    }

    /**
     * Bind the the data to the views
     *
     * @return void
     */
    protected function composeView()
    {
        view()->composer([
            'admin_api::integrations.api-keys.index',
            'admin_api::integrations.api-keys.create',
            'admin_api::integrations.api-keys.edit',
        ], function ($view) {
            $view->with('acl', $this->createACL());
        });
    }

    /**
     * Registers acl to entire application
     *
     * @return void
     */
    public function registerACL()
    {
        $this->app->singleton('api-acl', function () {
            return $this->createACL();
        });
    }

    /**
     * Create ACL tree.
     *
     * @return mixed
     */
    protected function createACL()
    {
        static $tree;

        if ($tree) {
            return $tree;
        }

        $tree = Tree::create();

        foreach (config('api-acl') as $item) {
            $tree->add($item, 'acl');
        }

        $tree->items = core()->sortItems($tree->items);

        return $tree;
    }
}
