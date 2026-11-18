<?php

namespace Mothership\Product\Providers;

use Mothership\Core\Providers\CoreModuleServiceProvider;
use Mothership\Product\Models\AssociationType;
use Mothership\Product\Models\AssociationTypeField;
use Mothership\Product\Models\AssociationTypeFieldOption;
use Mothership\Product\Models\AssociationTypeFieldOptionTranslation;
use Mothership\Product\Models\AssociationTypeFieldTranslation;
use Mothership\Product\Models\AssociationTypeTranslation;
use Mothership\Product\Models\Product;
use Mothership\Product\Models\ProductAssociation;
use Mothership\Product\Models\ProductGridView;
use Mothership\Product\Models\VariantStructure;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        Product::class,
        AssociationType::class,
        AssociationTypeTranslation::class,
        AssociationTypeField::class,
        AssociationTypeFieldTranslation::class,
        AssociationTypeFieldOption::class,
        AssociationTypeFieldOptionTranslation::class,
        ProductAssociation::class,
        ProductGridView::class,
        VariantStructure::class,
    ];
}
