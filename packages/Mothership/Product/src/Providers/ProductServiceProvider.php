<?php

namespace Mothership\Product\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Mothership\Product\Console\ResyncVariantsCommand;
use Mothership\Product\Console\StripRedundantVariantValuesCommand;
use Mothership\Product\Contracts\VariantStructurePlanner as VariantStructurePlannerContract;
use Mothership\Product\Contracts\VariantValueResolver as VariantValueResolverContract;
use Mothership\Product\Facades\ProductImage as ProductImageFacade;
use Mothership\Product\Facades\ProductVideo as ProductVideoFacade;
use Mothership\Product\Facades\ValueSetter as ProductValueSetter;
use Mothership\Product\Filter\Database\BooleanFilter as DatabaseBooleanFilter;
use Mothership\Product\Filter\Database\DateFilter as DatabaseDateFilter;
use Mothership\Product\Filter\Database\PriceFilter as DatabasePriceFilter;
use Mothership\Product\Filter\Database\Property\CategoryFilter as DatabaseCategoryFilter;
use Mothership\Product\Filter\Database\Property\CompletenessFilter as DatabaseCompletenessFilter;
use Mothership\Product\Filter\Database\Property\DateTimeFilter as DatabaseDateTimeFilter;
use Mothership\Product\Filter\Database\Property\FamilyFilter as DatabaseFamilyFilter;
use Mothership\Product\Filter\Database\Property\IdFilter as DatabaseIdFilter;
use Mothership\Product\Filter\Database\Property\ParentFilter as DatabaseParentFilter;
use Mothership\Product\Filter\Database\Property\SkuFilter as DatabaseSkuFilter;
use Mothership\Product\Filter\Database\Property\StatusFilter as DatabaseStatusFilter;
use Mothership\Product\Filter\Database\Property\TypeFilter as DatabaseTypeFilter;
use Mothership\Product\Filter\Database\TextFilter as DatabaseTextFilter;
use Mothership\Product\Filter\ElasticSearch\BooleanFilter as ElasticSearchBooleanFilter;
use Mothership\Product\Filter\ElasticSearch\DateFilter as ElasticSearchDateFilter;
use Mothership\Product\Filter\ElasticSearch\DateTimeFilter as ElasticSearchDateTimeAttributeFilter;
use Mothership\Product\Filter\ElasticSearch\DefaultFilter as ElasticSearchDefaultFilter;
use Mothership\Product\Filter\ElasticSearch\OptionFilter as ElasticSearchOptionFilter;
use Mothership\Product\Filter\ElasticSearch\PriceFilter as ElasticSearchPriceFilter;
use Mothership\Product\Filter\ElasticSearch\Property\CategoryFilter as ElasticSearchCategoryFilter;
use Mothership\Product\Filter\ElasticSearch\Property\CompletenessFilter as ElasticSearchCompletenessFilter;
use Mothership\Product\Filter\ElasticSearch\Property\DateTimeFilter as ElasticSearchDateTimeFilter;
use Mothership\Product\Filter\ElasticSearch\Property\FamilyFilter as ElasticSearchFamilyFilter;
use Mothership\Product\Filter\ElasticSearch\Property\IdFilter as ElasticSearchIdFilter;
use Mothership\Product\Filter\ElasticSearch\Property\ParentFilter as ElasticSearchParentFilter;
use Mothership\Product\Filter\ElasticSearch\Property\SkuFilter as ElasticSearchSkuFilter;
use Mothership\Product\Filter\ElasticSearch\Property\StatusFilter as ElasticSearchStatusFilter;
use Mothership\Product\Filter\ElasticSearch\Property\TypeFilter as ElasticSearchTypeFilter;
use Mothership\Product\Filter\ElasticSearch\TextFilter as ElasticSearchTextFilter;
use Mothership\Product\Models\ProductProxy;
use Mothership\Product\Observers\ProductObserver;
use Mothership\Product\ProductImage;
use Mothership\Product\ProductVideo;
use Mothership\Product\Services\ProductValueMapper;
use Mothership\Product\Services\VariantStructurePlanner;
use Mothership\Product\Services\VariantValueResolver;
use Mothership\Product\ValueSetter;

class ProductServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        include __DIR__.'/../Http/helpers.php';

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'product');

        $this->app->register(EventServiceProvider::class);

        ProductProxy::observe(ProductObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                StripRedundantVariantValuesCommand::class,
                ResyncVariantsCommand::class,
            ]);
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();

        $this->registerFacades();

        $this->registerTags();

        $this->registerBindings();
    }

    /**
     * Register overridable service bindings.
     */
    protected function registerBindings(): void
    {
        // Bound (fresh per resolve), not scoped: the per-product memo must not survive a same-request mutation.
        $this->app->bind(VariantValueResolverContract::class, VariantValueResolver::class);
        $this->app->bind(VariantStructurePlannerContract::class, VariantStructurePlanner::class);
    }

    /**
     * Register configuration.
     */
    public function registerConfig(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/product_types.php', 'product_types');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/products.php', 'products');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/association_field_types.php', 'association_field_types');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/product_editor.php', 'product_editor');

    }

    /**
     * Register Bouncer as a singleton.
     */
    protected function registerFacades(): void
    {
        /**
         * Product image.
         */
        $loader = AliasLoader::getInstance();

        $loader->alias('product_image', ProductImageFacade::class);

        $this->app->singleton('product_image', fn () => app()->make(ProductImage::class));

        /**
         * Product video.
         */
        $loader->alias('product_video', ProductVideoFacade::class);

        $this->app->singleton('product_video', fn () => app()->make(ProductVideo::class));

        /**
         * Product Values setter
         */
        $loader->alias('value_setter', ProductValueSetter::class);

        $this->app->singleton('value_setter', fn () => app()->make(ValueSetter::class));

        /**
         * Product value mapper
         */
        $this->app->singleton('product_value_mapper', fn ($app): ProductValueMapper => new ProductValueMapper);
    }

    protected function registerTags(): void
    {
        $this->app->tag([
            ElasticSearchTextFilter::class,
            ElasticSearchBooleanFilter::class,
            ElasticSearchDateFilter::class,
            ElasticSearchDateTimeAttributeFilter::class,
            ElasticSearchPriceFilter::class,
            ElasticSearchOptionFilter::class,
            ElasticSearchDefaultFilter::class,
        ], 'mothership.elasticsearch.attribute.filters');

        $this->app->tag([
            ElasticSearchTypeFilter::class,
            ElasticSearchStatusFilter::class,
            ElasticSearchIdFilter::class,
            ElasticSearchFamilyFilter::class,
            ElasticSearchSkuFilter::class,
            ElasticSearchDateTimeFilter::class,
            ElasticSearchParentFilter::class,
            ElasticSearchCompletenessFilter::class,
            ElasticSearchCategoryFilter::class,
        ], 'mothership.elasticsearch.product.property.filters');

        $this->app->tag([
            DatabaseTextFilter::class,
            DatabaseBooleanFilter::class,
            DatabaseDateFilter::class,
            DatabasePriceFilter::class,
        ], 'mothership.database.attribute.filters');

        $this->app->tag([
            DatabaseFamilyFilter::class,
            DatabaseIdFilter::class,
            DatabaseSkuFilter::class,
            DatabaseStatusFilter::class,
            DatabaseTypeFilter::class,
            DatabaseParentFilter::class,
            DatabaseDateTimeFilter::class,
            DatabaseCompletenessFilter::class,
            DatabaseCategoryFilter::class,
        ], 'mothership.database.product.property.filters');
    }
}
