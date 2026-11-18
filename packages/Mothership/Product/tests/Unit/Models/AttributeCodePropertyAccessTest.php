<?php

use Mothership\Attribute\Models\Attribute;
use Mothership\Attribute\Models\AttributeFamily;
use Mothership\Attribute\Models\AttributeGroup;
use Mothership\Product\Models\Product;

/*
 * Guards M3: the legacy getAttribute() override routed attribute-code property
 * access into getCustomAttributeValue(), which queried a non-existent
 * `attribute_values` relation and fatally errored. Values now live in the
 * `values` JSON column, so reading an attribute code as a property must resolve
 * harmlessly (null) instead of crashing.
 */
it('does not crash when reading an attribute code as a property (M3)', function () {
    $family = AttributeFamily::factory()->create();
    $group = AttributeGroup::factory()->create();
    $family->familyGroups()->attach($group);

    $custom = Attribute::factory()->create(['type' => 'text', 'code' => 'custom_material_'.uniqid()]);
    $family->attributeFamilyGroupMappings()->first()->customAttributes()->attach($custom, ['position' => 1]);

    $product = Product::factory()->simple()->create(['attribute_family_id' => $family->id]);

    expect($product->{$custom->code})->toBeNull();
});
