<?php

namespace Mothership\Product\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Mothership\Product\Listeners\CopyVariantStructure;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'catalog.attribute_family.copied' => [
            CopyVariantStructure::class.'@handle',
        ],
    ];
}
