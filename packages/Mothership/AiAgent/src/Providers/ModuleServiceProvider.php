<?php

namespace Mothership\AiAgent\Providers;

use Mothership\AiAgent\Models\Agent;
use Mothership\AiAgent\Models\AgentExecution;
use Mothership\AiAgent\Models\Credential;
use Mothership\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    /**
     * Models to register their repository bindings.
     *
     * @var array
     */
    protected $models = [
        Credential::class,
        Agent::class,
        AgentExecution::class,
    ];
}
