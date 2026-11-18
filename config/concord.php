<?php

use Mothership\Admin\Providers\ModuleServiceProvider;
use Mothership\Core\CoreConvention;

return [

    'convention' => CoreConvention::class,

    'modules' => [

        /**
         * Example:
         * VendorA\ModuleX\Providers\ModuleServiceProvider::class,
         * VendorB\ModuleY\Providers\ModuleServiceProvider::class
         */
        ModuleServiceProvider::class,
        Mothership\Attribute\Providers\ModuleServiceProvider::class,
        Mothership\Category\Providers\ModuleServiceProvider::class,
        Mothership\Core\Providers\ModuleServiceProvider::class,
        Mothership\DataTransfer\Providers\ModuleServiceProvider::class,
        Mothership\HistoryControl\Providers\ModuleServiceProvider::class,
        Mothership\Notification\Providers\ModuleServiceProvider::class,
        Mothership\Product\Providers\ModuleServiceProvider::class,
        Mothership\User\Providers\ModuleServiceProvider::class,
        Mothership\MagicAI\Providers\ModuleServiceProvider::class,
        Mothership\AiAgent\Providers\ModuleServiceProvider::class,
        Mothership\Measurement\Providers\ModuleServiceProvider::class,
    ],
];
