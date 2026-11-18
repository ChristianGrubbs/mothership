<?php

namespace Mothership\DataTransfer\Providers;

use Mothership\Core\Providers\CoreModuleServiceProvider;
use Mothership\DataTransfer\Models\JobInstances;
use Mothership\DataTransfer\Models\JobTrack;
use Mothership\DataTransfer\Models\JobTrackBatch;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        JobInstances::class,
        JobTrackBatch::class,
        JobTrack::class,
    ];
}
