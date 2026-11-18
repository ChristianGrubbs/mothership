<?php

namespace Mothership\Completeness\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mothership\Completeness\Console\RecalculateCompletenessCommand;
use Mothership\Completeness\Listeners\HandleFamilyAttributeChanges;
use Mothership\Completeness\Observers\Product as CompletenessProductObserver;
use Mothership\Product\Models\ProductProxy;

class CompletenessServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/completeness.php', 'completeness');

        Route::middleware('web')->group(__DIR__.'/../Routes/web.php');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'completeness');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'completeness');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app->register(ModuleServiceProvider::class);

        ProductProxy::observe(CompletenessProductObserver::class);

        Event::listen('catalog.attribute_family.attributes.changed', HandleFamilyAttributeChanges::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RecalculateCompletenessCommand::class,
            ]);
        }
    }
}
