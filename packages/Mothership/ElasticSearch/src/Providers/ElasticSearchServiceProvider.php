<?php

namespace Mothership\ElasticSearch\Providers;

use Illuminate\Support\ServiceProvider;
use Mothership\Category\Models\Category as Categories;
use Mothership\ElasticSearch\Console\Command\CategoryIndexer;
use Mothership\ElasticSearch\Console\Command\ProductIndexer;
use Mothership\ElasticSearch\Console\Command\Reindexer;
use Mothership\ElasticSearch\ElasticSearchQuery;
use Mothership\ElasticSearch\Observers\Category;
use Mothership\ElasticSearch\Observers\Product;
use Mothership\Product\Models\Product as Products;

class ElasticSearchServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        Products::observe(Product::class);
        Categories::observe(Category::class);
    }

    /**
     * Register the service provider
     */
    public function register(): void
    {
        $this->registerCommands();
        $this->registerFacades();
    }

    /**
     * Register the Installer Commands of this package.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProductIndexer::class,
                CategoryIndexer::class,
                Reindexer::class,
            ]);
        }
    }

    public function registerFacades(): void
    {
        $this->app->bind(ElasticSearchQuery::class);

        $this->app->singleton('elastic-search-query', fn ($app): ElasticSearchQuery => new ElasticSearchQuery);
    }
}
