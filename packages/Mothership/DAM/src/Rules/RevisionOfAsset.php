<?php

namespace Mothership\DAM\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

/**
 * A review comment's revision (P7) must be a file of the asset it is posted on: any revision of any of its slots.
 * Another asset's file fails with the message `exists` gives a missing id, so the field cannot probe other assets.
 * Like ReplyParent, it stays silent on an asset the author cannot open: that asset's own rule answers.
 */
class RevisionOfAsset implements ValidationRule
{
    public function __construct(protected int $assetId) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! app(AssetAccessDecision::class)->for(AccessContext::library(), $this->assetId)->visible) {
            return;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false || ! AssetFile::query()->whereKey($id)->where('dam_asset_id', $this->assetId)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
