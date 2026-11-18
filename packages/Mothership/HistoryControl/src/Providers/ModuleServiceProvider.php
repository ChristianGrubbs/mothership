<?php

namespace Mothership\HistoryControl\Providers;

use Mothership\Core\Providers\CoreModuleServiceProvider;
use Mothership\HistoryControl\Models\History;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        History::class,
    ];
}
