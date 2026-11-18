<?php

namespace Mothership\Notification\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Mothership\Notification\Events\NotificationEvent;
use Mothership\Notification\Listeners\NotificationListener;
use Mothership\Notification\Listeners\SendNotificationListener;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        NotificationEvent::class => [
            NotificationListener::class,
        ],

        'data_transfer.export.completed' => [
            [SendNotificationListener::class, 'sendNotification'],
        ],

        'data_transfer.imports.completed' => [
            [SendNotificationListener::class, 'sendNotification'],
        ],

        'data_transfer.import.validate.state_failed' => [
            [SendNotificationListener::class, 'sendNotification'],
        ],
    ];

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot() {}
}
