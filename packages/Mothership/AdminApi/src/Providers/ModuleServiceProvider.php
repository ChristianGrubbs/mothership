<?php

namespace Mothership\AdminApi\Providers;

use Konekt\Concord\BaseModuleServiceProvider;
use Mothership\AdminApi\Models\Apikey;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        Apikey::class,
    ];
}
