<?php

namespace Mothership\Admin\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Mothership\Admin\Auth\CodeBrokerManager;
use Mothership\Admin\Auth\PasswordLogin;
use Mothership\Admin\Console\Commands\Access\GrantAccessCommand;
use Mothership\Admin\Console\Commands\Access\LinkAccessCommand;
use Mothership\Admin\Console\Commands\Access\ListAccessRequestsCommand;
use Mothership\Admin\Console\Commands\Access\RevokeAccessCommand;
use Mothership\Admin\Console\Commands\RefreshDashboardCacheCommand;
use Mothership\Admin\Fields\FieldConfig;
use Mothership\Admin\Http\Middleware\EnsurePimEnabled;
use Mothership\Admin\Observers\CategoryObserver;
use Mothership\Admin\Observers\ConfigurationObserver;
use Mothership\Admin\Observers\ProductObserver;
use Mothership\Admin\Sso\SsoManager;
use Mothership\Attribute\Models\AttributeFamilyProxy;
use Mothership\Attribute\Models\AttributeGroupProxy;
use Mothership\Attribute\Models\AttributeProxy;
use Mothership\Category\Models\CategoryProxy;
use Mothership\Core\Models\ChannelProxy;
use Mothership\Core\Models\CurrencyProxy;
use Mothership\Core\Models\LocaleProxy;
use Mothership\Core\Tree;
use Mothership\Product\Models\ProductProxy;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('pim.enabled', EnsurePimEnabled::class);

        $this->configureRateLimiting();

        // Every admin `{id}` is an auto-increment primary key, so constrain it to
        // digits group-wide: a non-numeric id yields a clean 404 instead of a 500
        // from the model lookup. Non-numeric identifiers use `code`/`slug` params.
        Route::middleware('web')
            ->where(['id' => '[0-9]+'])
            ->group(__DIR__.'/../Routes/web.php');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'admin');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'admin');

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'admin');

        $this->composeView();

        $this->registerACL();

        $this->app->register(EventServiceProvider::class);

        ProductProxy::observe(ProductObserver::class);

        CategoryProxy::observe(CategoryObserver::class);

        AttributeProxy::observe(ConfigurationObserver::class);
        AttributeGroupProxy::observe(ConfigurationObserver::class);
        AttributeFamilyProxy::observe(ConfigurationObserver::class);
        LocaleProxy::observe(ConfigurationObserver::class);
        ChannelProxy::observe(ConfigurationObserver::class);
        CurrencyProxy::observe(ConfigurationObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshDashboardCacheCommand::class,
                ListAccessRequestsCommand::class,
                GrantAccessCommand::class,
                LinkAccessCommand::class,
                RevokeAccessCommand::class,
            ]);
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();

        $this->app->singleton(FieldConfig::class);

        $this->app->scoped(SsoManager::class, fn ($app): SsoManager => new SsoManager($app));

        // The password broker provider is deferred and binds its own manager when it loads, so extend rather than
        // bind: every database-driver broker then issues one-time codes.
        $this->app->extend('auth.password', fn ($manager, $app): CodeBrokerManager => new CodeBrokerManager($app));

        $this->app->scoped('mothership.admin.menu', fn (): array => $this->buildAdminMenu());
    }

    /**
     * Register package config.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php',
            'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php',
            'acl'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/system.php',
            'core'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/system_settings.php',
            'system_settings'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/help.php',
            'help'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/product_filter_operators.php',
            'product_filter_operators'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/auth.php',
            'admin.auth'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/sso.php',
            'sso'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/media.php',
            'admin.media'
        );
    }

    /**
     * Bind the data to the views.
     */
    protected function composeView(): void
    {
        view()->composer([
            'admin::components.layouts.header.index',
            'admin::components.layouts.sidebar.index',
            'admin::components.layouts.tabs',
            'admin::components.breadcrumbs',
        ], function ($view) {
            ['tree' => $tree, 'landingUrl' => $landingUrl] = app('mothership.admin.menu');

            $view->with('menu', $tree);
            $view->with('adminLandingUrl', $landingUrl);
        });

        view()->composer('admin::components.layouts.header.index', function ($view) {
            ['accountMenu' => $accountMenu] = app('mothership.admin.menu');

            $view->with('accountMenu', $accountMenu);
        });

        view()->composer([
            'admin::settings.roles.create',
            'admin::settings.roles.edit',
        ], function ($view) {
            $view->with('acl', $this->createACL());
        });
    }

    /**
     * Build the authorized admin sidebar menu once per request. Shared by the
     * header, sidebar, tabs and breadcrumb views via the `mothership.admin.menu`
     * scoped binding instead of rebuilding the tree for each.
     *
     * @return array{tree: Tree, landingUrl: string, accountMenu: array<string, array{heading: string, entries: array<int, array<string, mixed>>}>}
     */
    protected function buildAdminMenu(): array
    {
        $tree = Tree::create();

        $pimEnabled = (bool) config('mothership.pim_enabled', false);
        $pimKeys = (array) config('mothership.pim_menu_keys', [
            'dashboard',
            'catalog',
            'data_transfer',
            'ai-agent',
        ]);

        foreach (config('menu.admin') as $item) {
            if (! $pimEnabled && $this->isPimMenuItem($item['key'] ?? '', $pimKeys)) {
                continue;
            }

            if (! empty($item['superuser']) && auth('admin')->user()?->role?->permission_type !== 'all') {
                continue;
            }

            // A menu item may gate on a permission other than its own key (e.g. a top-level
            // shortcut whose dotless key has no ACL entry of its own points 'acl' at a real one).
            if (! bouncer()->hasPermission($item['acl'] ?? $item['key'])) {
                continue;
            }

            $tree->add($item, 'menu');
        }

        $tree->items = core()->sortItems($tree->items);
        $tree->items = $tree->removeUnauthorizedUrls();

        if (! $tree->currentKey) {
            $tree->currentKey = $this->resolveActiveMenuKey();
        }

        $landing = collect($tree->items)->first(fn (array $item): bool => ! empty($item['url']));

        return [
            'tree'        => $tree,
            'landingUrl'  => $landing['url'] ?? route('admin.session.create'),
            'accountMenu' => $this->buildAccountMenu($tree->items),
        ];
    }

    /**
     * Group ACL-filtered menu items tagged for account-menu placement (see
     * the `menu.php` `'placement' => 'account'` items) into avatar-dropdown
     * sections keyed by the section-root's own key (e.g. 'settings',
     * 'configuration'), heading first. Section headings are read from the
     * raw config rather than the filtered tree so a section still reads
     * correctly when an unrelated descendant joined it via `account_section`
     * even though the section-root item itself failed its own gate (e.g. a
     * role with `dam` + `dam.configuration` but not the unrelated global
     * `configuration` permission) — an item's visibility here never depends
     * on anything but that item's own `acl`/`key`. A section with no
     * authorized entries is dropped entirely.
     *
     * @param  array<int|string, array<string, mixed>>  $items  the ACL-filtered, sorted tree
     * @return array<string, array{heading: string, entries: array<int, array<string, mixed>>}>
     */
    protected function buildAccountMenu(array $items): array
    {
        $sections = collect(config('menu.admin'))
            ->filter(fn (array $item): bool => ($item['placement'] ?? null) === 'account' && ! str_contains($item['key'], '.'))
            ->sortBy('sort')
            ->mapWithKeys(fn (array $item): array => [$item['key'] => ['heading' => $item['name'], 'entries' => []]])
            ->all();

        // An authorized section-root contributes its own (already gated,
        // sorted) children.
        foreach ($items as $item) {
            if (isset($sections[$item['key']])) {
                $sections[$item['key']]['entries'] = array_values($item['children'] ?? []);
            }
        }

        $this->collectTaggedAccountEntries($items, $sections);

        return collect($sections)
            ->reject(fn (array $section): bool => $section['entries'] === [])
            ->map(function (array $section): array {
                // 'account_sort' orders an entry within the dropdown only, independent of its
                // 'sort' (which the settings tabs, breadcrumbs and the parent's landing url
                // still read unchanged) — see menu.php's 'settings.*' items for why the two
                // diverge here. Entries without one (e.g. Configuration's children) keep
                // ordering by 'sort' exactly as before.
                usort($section['entries'], fn (array $a, array $b): int => ($a['account_sort'] ?? $a['sort'] ?? 0) <=> ($b['account_sort'] ?? $b['sort'] ?? 0));

                return $section;
            })
            ->all();
    }

    /**
     * Recursively fold in items tagged for account placement that live
     * outside their target section's own branch — kept there so their own
     * gating stays untouched (e.g. `dam.configuration`, nested under
     * Library, joins the Configuration section via `account_section` without
     * being re-parented under the unrelated global `configuration` key).
     *
     * @param  array<int|string, array<string, mixed>>  $items
     * @param  array<string, array{heading: string, entries: array<int, array<string, mixed>>}>  $sections
     */
    protected function collectTaggedAccountEntries(array $items, array &$sections): void
    {
        foreach ($items as $item) {
            $sectionKey = $item['account_section'] ?? null;

            if ($sectionKey !== null && isset($sections[$sectionKey])) {
                $sections[$sectionKey]['entries'][] = $item;
            }

            if (! empty($item['children'])) {
                $this->collectTaggedAccountEntries($item['children'], $sections);
            }
        }
    }

    /**
     * Check if a menu key belongs to the PIM surface.
     */
    protected function isPimMenuItem(string $key, array $pimKeys): bool
    {
        foreach ($pimKeys as $pimKey) {
            if ($key === $pimKey || str_starts_with($key, $pimKey.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the sidebar menu key for an off-menu route whose URL prefix-matched
     * no menu item (e.g. a detail route `admin.magic_ai.prompt.edit` or a hub page
     * like appearance). First matches the current route name against menu items by
     * route-name ancestry (most specific wins), then falls back to the System
     * Settings hub mapping.
     */
    protected function resolveActiveMenuKey(): ?string
    {
        $currentRoute = Route::currentRouteName();

        if (! $currentRoute) {
            return null;
        }

        $bestKey = null;
        $bestLength = 0;

        $pimEnabled = (bool) config('mothership.pim_enabled', false);
        $pimKeys = (array) config('mothership.pim_menu_keys', [
            'dashboard',
            'catalog',
            'data_transfer',
            'ai-agent',
        ]);

        foreach (config('menu.admin') as $item) {
            if (empty($item['route']) || empty($item['key'])) {
                continue;
            }

            if (! $pimEnabled && $this->isPimMenuItem($item['key'], $pimKeys)) {
                continue;
            }

            $routeBase = Str::beforeLast($item['route'], '.');

            if ($currentRoute === $item['route'] || Str::startsWith($currentRoute, $routeBase.'.')) {
                if (strlen($routeBase) > $bestLength) {
                    $bestLength = strlen($routeBase);
                    $bestKey = $item['key'];
                }
            }
        }

        return $bestKey ?? $this->resolveHubMenuKey();
    }

    /**
     * Resolve the sidebar menu key that owns the current System Settings hub
     * route. Returns the parent menu key (the hub row's `acl`) so off-menu hub
     * pages activate their sidebar group, or null when the current route is not
     * a hub page.
     */
    protected function resolveHubMenuKey(): ?string
    {
        $currentRoute = Route::currentRouteName();

        if (! $currentRoute) {
            return null;
        }

        foreach (config('system_settings') as $item) {
            if (empty($item['route']) || empty($item['acl'])) {
                continue;
            }

            $routeBase = Str::beforeLast($item['route'], '.');

            if ($currentRoute === $item['route'] || Str::startsWith($currentRoute, $routeBase.'.')) {
                return $item['acl'];
            }
        }

        return null;
    }

    /**
     * Register ACL to entire application.
     */
    protected function registerACL(): void
    {
        $this->app->singleton('acl', function () {
            return $this->createACL();
        });
    }

    /**
     * Create ACL tree.
     */
    protected function createACL(): Tree
    {
        static $tree;

        if ($tree) {
            return $tree;
        }

        $tree = Tree::create();

        foreach (config('acl') as $item) {
            $tree->add($item, 'acl');
        }

        $tree->items = core()->sortItems($tree->items);

        return $tree;
    }

    /**
     * The answer to a sign-in attempt past the limit: the sign-in page with the wait in seconds, or, for the
     * ajax form, the same message in JSON beside the usual {error, description} pair.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function loginThrottledResponse(Request $request, array $headers): SymfonyResponse
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

        $message = trans('admin::app.users.sessions.too-many-attempts-wait', ['seconds' => $seconds]);

        if ($request->wantsJson()) {
            return response()->json([
                'error'       => trans('admin::app.errors.429.title'),
                'description' => trans('admin::app.errors.429.description'),
                'message'     => $message,
            ], SymfonyResponse::HTTP_TOO_MANY_REQUESTS, $headers);
        }

        // For this response only: the next page load must not repeat the message.
        session()->now('error', $message);
        session()->now('_old_input', $request->only('email'));

        return response()->view('admin::users.sessions.create', [
            'ssoProviders'  => app(SsoManager::class)->enabled(),
            'passwordLogin' => app(PasswordLogin::class)->enabled(),
        ], SymfonyResponse::HTTP_TOO_MANY_REQUESTS, $headers);
    }

    /**
     * Configure rate limiters for admin authentication routes.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('admin-login', function (Request $request) {
            $key = strtolower(trim((string) $request->input('email', ''))).'|'.$request->ip();

            $maxAttempts = config('admin.auth.login_rate_limit', 5);

            // A refused attempt stays on the sign-in page and says how long to wait (SETUP-09), instead of a bare
            // "Too Many Requests" page. It is still a 429, so SignalLoginLockout fires Lockout as before.
            return Limit::perMinute(is_numeric($maxAttempts) ? (int) $maxAttempts : 5)
                ->by($key)
                ->response(fn (Request $request, array $headers) => $this->loginThrottledResponse(request: $request, headers: $headers));
        });

        RateLimiter::for('admin-forgot-password', function (Request $request) {
            $key = strtolower(trim((string) $request->input('email', ''))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('admin-reset-password', function (Request $request) {
            $key = strtolower(trim((string) $request->input('email', ''))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('admin-invite-accept', function (Request $request) {
            return [
                Limit::perMinute(5)->by(strtolower(trim((string) $request->input('email', ''))).'|'.$request->ip()),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('admin-invite', function (Request $request) {
            return Limit::perMinute(10)->by((string) optional($request->user('admin'))->id.'|'.$request->ip());
        });

        RateLimiter::for('admin-sudo', function (Request $request) {
            $key = (string) optional($request->user('admin'))->id.'|'.$request->ip();

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('admin-sso', function (Request $request) {
            $sessionId = optional($request->session())->getId() ?: 'guest';
            $key = $sessionId.'|'.$request->ip();

            return Limit::perMinute(20)->by($key);
        });
    }
}
