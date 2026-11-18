<?php

namespace Mothership\Webhook\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mothership\HistoryControl\Services\HistoryEntityAccess;
use Mothership\Webhook\Console\Commands\PruneWebhookLogs;
use Mothership\Webhook\Registry\EventRegistry;
use Mothership\Webhook\Services\RecentProductAudits;

class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Route::middleware('web')->group(__DIR__.'/../Routes/web.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'webhook');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'webhook');

        $this->app->register(EventServiceProvider::class);

        // The history routes check only the history ACL. A webhook's history (its URL, its custom headers) is
        // shown on its edit page, so only those who may edit webhooks read it.
        $this->app->make(HistoryEntityAccess::class)->register(
            'webhooks',
            fn (int $webhookId): bool => bouncer()->hasPermission('configuration.webhook.edit'),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([PruneWebhookLogs::class]);

            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)->command('webhook:logs:prune')->daily();
            });
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();

        $this->app->scoped(RecentProductAudits::class);

        $this->app->singleton(EventRegistry::class, fn (): EventRegistry => new EventRegistry(
            config('webhook.events', [])
        ));
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php', 'acl'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/webhook.php', 'webhook'
        );
    }
}
