<?php

namespace Mothership\DataTransfer\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Mothership\DataTransfer\Console\JobExecuteCommand;
use Mothership\DataTransfer\Console\ReapStalledJobsCommand;
use Mothership\DataTransfer\Queue\Worker;
use Mothership\DataTransfer\Repositories\JobInstancesRepository;
use Mothership\DataTransfer\Repositories\JobTrackRepository;
use Mothership\User\Repositories\AdminRepository;

class DataTransferServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'data_transfer');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->publishes([
            __DIR__.'/../Resources/samples' => storage_path('app/public/data-transfer/samples'),
        ], 'mothership-data-transfer-samples');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/importers.php', 'importers');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/exporters.php', 'exporters');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/quick_exporters.php', 'quick_exporters');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/actions.php', 'import_settings');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/actions.php', 'export_settings');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/image_import.php', 'image_import');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/job_health.php', 'job_health');

        $this->registerWorker();

        $this->registerCommands();
    }

    /**
     * Register the queue worker.
     *
     * @return void
     */
    protected function registerWorker()
    {
        $this->app->singleton('mothership.singlejob.queue.worker', function ($app): Worker {
            $isDownForMaintenance = (fn () => $this->app->isDownForMaintenance());

            $resetScope = function () use ($app): void {
                $app['log']->flushSharedContext();

                if (method_exists($app['log'], 'withoutContext')) {
                    $app['log']->withoutContext();
                }

                if (method_exists($app['db'], 'getConnections')) {
                    foreach ($app['db']->getConnections() as $connection) {
                        $connection->resetTotalQueryDuration();
                        $connection->allowQueryDurationHandlersToRunAgain();
                    }
                }

                $app->forgetScopedInstances();

                Facade::clearResolvedInstances();
            };

            return new Worker(
                $app['queue'],
                $app['events'],
                $app[ExceptionHandler::class],
                $isDownForMaintenance,
                $resetScope
            );
        });
    }

    /**
     * Register the Job Execute Commands of this package.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                JobExecuteCommand::class,
                ReapStalledJobsCommand::class,
            ]);
        }

        $this->app->singleton(JobExecuteCommand::class, fn ($app): JobExecuteCommand => new JobExecuteCommand(
            $app['mothership.singlejob.queue.worker'],
            $app['cache.store'],
            $app->make(JobInstancesRepository::class),
            $app->make(JobTrackRepository::class),
            $app->make(AdminRepository::class),
        ));
    }
}
