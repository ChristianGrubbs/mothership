<?php

namespace Mothership\DAM\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

/**
 * `exists:dam_assets,id` that also refuses an asset the viewer cannot open, with the exact message
 * `exists` gives a missing id, so a request body cannot be used to probe whether a hidden asset exists.
 */
class VisibleAsset implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false || ! app(AssetAccessDecision::class)->for(AccessContext::library(), $id)->visible) {
            $fail('validation.exists')->translate();
        }
    }
}
