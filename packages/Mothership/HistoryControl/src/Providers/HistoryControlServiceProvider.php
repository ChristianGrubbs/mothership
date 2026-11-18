<?php

namespace Mothership\HistoryControl\Providers;

use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Mothership\HistoryControl\Resolvers\ActorResolver;
use Mothership\HistoryControl\Services\HistoryEntityAccess;

class HistoryControlServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the history control services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app->register(EventServiceProvider::class);

        Context::dehydrating(fn (Repository $context) => ActorResolver::rememberDispatcher($context));
    }

    /**
     * Register the history control services.
     */
    public function register(): void
    {
        $this->app->singleton(HistoryEntityAccess::class);
    }
}
