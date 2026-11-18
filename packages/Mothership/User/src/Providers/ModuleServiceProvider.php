<?php

namespace Mothership\User\Providers;

use Mothership\Core\Providers\CoreModuleServiceProvider;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminPromoDismissal;
use Mothership\User\Models\Role;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        Admin::class,
        Role::class,
        AdminPromoDismissal::class,
    ];
}
