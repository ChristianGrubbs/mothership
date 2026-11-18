<?php

namespace Mothership\Core\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Mothership\Core\Listeners\CleanCacheRepository;
use Prettus\Repository\Events\RepositoryEntityCreated;
use Prettus\Repository\Events\RepositoryEntityDeleted;
use Prettus\Repository\Events\RepositoryEntityUpdated;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        RepositoryEntityCreated::class => [
            CleanCacheRepository::class,
        ],
        RepositoryEntityUpdated::class => [
            CleanCacheRepository::class,
        ],
        RepositoryEntityDeleted::class => [
            CleanCacheRepository::class,
        ],
    ];
}
