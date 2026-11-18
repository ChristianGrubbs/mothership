<?php

namespace Mothership\Core\Providers;

use Mothership\Core\Models\Channel;
use Mothership\Core\Models\CoreConfig;
use Mothership\Core\Models\Currency;
use Mothership\Core\Models\Locale;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        Channel::class,
        CoreConfig::class,
        Currency::class,
        Locale::class,
    ];
}
