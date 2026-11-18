<?php

namespace Mothership\Completeness\Providers;

use Mothership\Completeness\Models\CompletenessSetting;
use Mothership\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        CompletenessSetting::class,
    ];
}
