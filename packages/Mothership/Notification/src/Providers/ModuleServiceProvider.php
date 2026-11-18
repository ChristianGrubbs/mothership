<?php

namespace Mothership\Notification\Providers;

use Mothership\Core\Providers\CoreModuleServiceProvider;
use Mothership\Notification\Models\Notification;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        Notification::class,
    ];
}
