<?php

namespace Mothership\Attribute\Providers;

use Mothership\Attribute\Models\Attribute;
use Mothership\Attribute\Models\AttributeFamily;
use Mothership\Attribute\Models\AttributeFamilyGroupMapping;
use Mothership\Attribute\Models\AttributeFamilyTranslation;
use Mothership\Attribute\Models\AttributeGroup;
use Mothership\Attribute\Models\AttributeGroupTranslation;
use Mothership\Attribute\Models\AttributeOption;
use Mothership\Attribute\Models\AttributeOptionTranslation;
use Mothership\Attribute\Models\AttributeTranslation;
use Mothership\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        Attribute::class,
        AttributeFamily::class,
        AttributeGroup::class,
        AttributeOption::class,
        AttributeOptionTranslation::class,
        AttributeTranslation::class,
        AttributeGroupTranslation::class,
        AttributeFamilyTranslation::class,
        AttributeFamilyGroupMapping::class,
    ];
}
