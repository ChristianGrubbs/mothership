<?php

namespace Mothership\Category\Providers;

use Illuminate\Support\ServiceProvider;
use Mothership\Category\Models\CategoryProxy;
use Mothership\Category\Observers\CategoryObserver;
use Mothership\Category\Services\CategoryAdditionalDataMapper;

class CategoryServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        CategoryProxy::observe(CategoryObserver::class);
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();

        $this->registerFacades();
    }

    /**
     * Register configuration.
     */
    public function registerConfig(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/category_field_types.php', 'category_field_types');
    }

    /**
     * Register Bouncer as a singleton.
     */
    protected function registerFacades(): void
    {
        /**
         * Product value mapper
         */
        $this->app->singleton('category_additional_data_mapper', fn ($app): CategoryAdditionalDataMapper => new CategoryAdditionalDataMapper);
    }
}
