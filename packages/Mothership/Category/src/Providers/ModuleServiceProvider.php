<?php

namespace Mothership\Category\Providers;

use Mothership\Category\Models\Category;
use Mothership\Category\Models\CategoryField;
use Mothership\Category\Models\CategoryFieldOption;
use Mothership\Category\Models\CategoryFieldOptionTranslation;
use Mothership\Category\Models\CategoryFieldTranslation;
use Mothership\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        Category::class,
        CategoryField::class,
        CategoryFieldOption::class,
        CategoryFieldOptionTranslation::class,
        CategoryFieldTranslation::class,

    ];
}
