<?php

namespace Mothership\MagicAI\Providers;

use Konekt\Concord\BaseModuleServiceProvider;
use Mothership\MagicAI\Models\MagicAIPlatform;
use Mothership\MagicAI\Models\MagicAISystemPrompt;
use Mothership\MagicAI\Models\MagicPrompt;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        MagicPrompt::class,
        MagicAISystemPrompt::class,
        MagicAIPlatform::class,
    ];
}
