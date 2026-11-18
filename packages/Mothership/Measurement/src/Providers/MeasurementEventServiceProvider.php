<?php

namespace Mothership\Measurement\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Mothership\Measurement\Listeners\SaveAttributeMeasurementAfterUpdate;
use Mothership\Measurement\Listeners\ValidateAttributeMeasurementBeforeUpdate;

class MeasurementEventServiceProvider extends ServiceProvider
{
    /**
     * Register the measurement event listeners.
     */
    public function boot(): void
    {
        Event::listen(
            'mothership.admin.catalog.attributes.edit.card.label.after',
            function ($viewRenderEventManager, $attribute = null): void {
                $viewRenderEventManager->addTemplate(
                    'measurement::catalog.attributes.edit'
                );
            }
        );

        Event::listen(
            'mothership.admin.products.dynamic-attribute-fields.control.measurement.before',
            function ($viewRenderEventManager): void {
                $viewRenderEventManager->addTemplate(
                    'measurement::catalog.products.edit'
                );
            }
        );

        Event::listen(
            'catalog.attribute.update.before',
            ValidateAttributeMeasurementBeforeUpdate::class.'@handle'
        );

        Event::listen(
            'catalog.attribute.update.after',
            SaveAttributeMeasurementAfterUpdate::class.'@handle'
        );
    }
}
