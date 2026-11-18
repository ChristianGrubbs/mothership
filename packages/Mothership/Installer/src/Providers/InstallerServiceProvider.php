<?php

namespace Mothership\Installer\Providers;

use Illuminate\Support\ServiceProvider;
use Mothership\Installer\Console\Commands\DefaultUser as DefaultUserCommand;
use Mothership\Installer\Console\Commands\Installer as InstallerCommand;
use Mothership\Installer\Console\Commands\PurgeUnusedImages as PurgeUnusedImagesCommand;
use Mothership\Installer\Console\Commands\SeedDemoData as SeedDemoDataCommand;
use Mothership\Installer\Http\Middleware\CanInstall;
use Mothership\Installer\Http\Middleware\Locale;

class InstallerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        $this->app['router']->middlewareGroup('install', [CanInstall::class]);

        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'installer');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'installer');

        $this->app['router']->aliasMiddleware('installer_locale', Locale::class);
    }

    /**
     * Register the service provider
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/installer.php', 'installer');

        $this->registerCommands();
    }

    /**
     * Register the Installer Commands of this package.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallerCommand::class,
                DefaultUserCommand::class,
                PurgeUnusedImagesCommand::class,
                SeedDemoDataCommand::class,
            ]);
        }
    }
}
