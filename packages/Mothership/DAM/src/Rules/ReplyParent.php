<?php

namespace Mothership\DAM\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

/**
 * A reply's parent must be a top-level comment on the same asset. Anything else (another asset's
 * comment, a reply, a missing id) fails with the message `exists` gives a missing id, so a reply can
 * neither land in a thread the author cannot open nor probe which comment ids exist.
 *
 * It says nothing about an asset the author cannot open (the same decision VisibleAsset makes): that asset's
 * own rule fails, and a parent error beside it would tell a comment on a hidden asset from a missing one.
 */
class ReplyParent implements ValidationRule
{
    public function __construct(protected int $assetId) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! app(AssetAccessDecision::class)->for(AccessContext::library(), $this->assetId)->visible) {
            return;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false
            || ! AssetComments::query()->whereKey($id)->where('dam_asset_id', $this->assetId)->whereNull('parent_id')->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
