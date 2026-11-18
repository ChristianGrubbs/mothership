<?php

it('dynamic-attribute-fields uses is_array() before exploding target locales', function () {
    $source = file_get_contents(
        base_path('packages/Mothership/Admin/src/Resources/views/components/products/dynamic-attribute-fields.blade.php')
    );

    expect($source)->toContain('is_array($targetlocales)');
});

it('dynamic-attribute-fields uses a ternary to safely handle array or string targetlocales', function () {
    $source = file_get_contents(
        base_path('packages/Mothership/Admin/src/Resources/views/components/products/dynamic-attribute-fields.blade.php')
    );

    expect($source)->toContain("is_array(\$targetlocales) ? \$targetlocales : explode(',', \$targetlocales ?? '')");
});
