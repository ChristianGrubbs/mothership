<?php

namespace Mothership\Measurement\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mothership\Attribute\Services\AttributeNormalizerFactory;
use Mothership\Measurement\Console\Commands\RecalculateMeasurementValues;
use Mothership\Measurement\Filter\Database\MeasurementFilter;
use Mothership\Measurement\Filter\ElasticSearch\MeasurementFilter as MeasurementElasticSearchFilter;
use Mothership\Measurement\Observers\ProductObserver;
use Mothership\Measurement\Services\Normalizers\MeasurementNormalizer;
use Mothership\Product\Models\Product;

class MeasurementServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->publishes([
            __DIR__.'/../Database/Seeders' => database_path('seeders'),
        ], 'measurement-seeders');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'measurement');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'measurement');

        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');

        $this->mergeConfigFrom(
            __DIR__.'/../Config/attribute_types.php',
            'attribute_types'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../Config/system_settings.php',
            'system_settings'
        );

        if ($this->app->runningInConsole()) {

            $this->commands([
                RecalculateMeasurementValues::class,
            ]);
        }

        Product::observe(ProductObserver::class);
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->register(MeasurementEventServiceProvider::class);

        $this->app->extend(AttributeNormalizerFactory::class, function (?object $factory): ?object {
            \Closure::bind(function (): void {
                $this->normalizers['measurement'] = MeasurementNormalizer::class;
            }, $factory, AttributeNormalizerFactory::class)();

            return $factory;
        });

        $this->app->tag(
            [MeasurementFilter::class],
            'mothership.database.attribute.filters'
        );

        $this->app->tag(
            [MeasurementElasticSearchFilter::class],
            'mothership.elasticsearch.attribute.filters'
        );

        $this->replaceConfigRecursivelyFrom(
            dirname(__DIR__).'/Config/product_filter_operators.php',
            'product_filter_operators'
        );

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php', 'acl'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/api-acl.php', 'api-acl'
        );
    }
}
