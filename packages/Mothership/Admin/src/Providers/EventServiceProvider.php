<?php

namespace Mothership\Admin\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Mothership\Admin\Listeners\Admin;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'admin.password.update.after' => [
            [Admin::class, 'afterPasswordUpdated'],
        ],
    ];
}
