<?php

namespace Mothership\Webhook\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Mothership\Product\Contracts\Product as ProductContract;
use Mothership\Webhook\Listeners\ImportBatch;
use Mothership\Webhook\Listeners\Product;
use Mothership\Webhook\Services\RecentProductAudits;
use OwenIt\Auditing\Events\Audited;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Event::listen(Audited::class, function (Audited $event): void {
            if (! $event->audit || ! $event->model instanceof ProductContract) {
                return;
            }

            app(RecentProductAudits::class)->remember($event->model->getKey(), $event->audit);
        });

        Event::listen('catalog.product.update.after', [Product::class, 'afterUpdate']);

        Event::listen('catalog.product.create.after', [Product::class, 'afterCreate']);

        Event::listen('catalog.product.bulk.edit.after', [Product::class, 'afterBulkEdit']);

        Event::listen('data_transfer.imports.batch.product.created.after', [Product::class, 'afterBulkCreate']);

        Event::listen('data_transfer.imports.batch.product.updated.after', [Product::class, 'afterBulkEditFromImport']);

        Event::listen('data_transfer.imports.batch.import.before', ImportBatch::class);
    }
}
