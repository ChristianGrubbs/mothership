<?php

namespace Mothership\User\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Mothership\User\Bouncer;
use Mothership\User\Facades\Bouncer as BouncerFacade;
use Mothership\User\Http\Middleware\AuthenticateAdminSession;
use Mothership\User\Http\Middleware\Bouncer as BouncerMiddleware;

class UserServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        include __DIR__.'/../Http/helpers.php';

        // Laravel's AuthenticateSession, pinned to the admin guard, runs ahead of the Bouncer on every admin route: a
        // session remembers the password hash it signed in with and ends once the account's password changes — by
        // its owner elsewhere, an admin, or a reset — so a password change signs that user out everywhere else (UX
        // eval 2026-10-10, PERM-12). The session that made a self-change is kept (AccountController, UserController).
        $this->app['router']->middlewareGroup('admin', [AuthenticateAdminSession::class, BouncerMiddleware::class]);

        // Its sign-out goes to the admin login; the framework default names a `login` route this app does not have.
        AuthenticateAdminSession::redirectUsing(fn () => route('admin.session.create'));

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerBouncer();
    }

    /**
     * Register Bouncer as a singleton.
     *
     * @return void
     */
    protected function registerBouncer()
    {
        $loader = AliasLoader::getInstance();
        $loader->alias('Bouncer', BouncerFacade::class);

        $this->app->singleton('bouncer', fn (): Bouncer => new Bouncer);
    }
}
