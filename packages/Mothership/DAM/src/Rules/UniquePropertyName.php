<?php

namespace Mothership\DAM\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;

/**
 * A free-text property name not already on the asset, compared trimmed and case-insensitively, so "G2-PROP" and
 * "g2-prop" are one name (UX eval G2-16). A field's value row is not a free-text property and never takes the name.
 * $language null: any language (the REST update's scope); $ignoreId: the row being edited; $currentName: that row's
 * name. An edit that keeps its row's name (in any case) adds no duplicate, so it passes even where older data already
 * holds case variants ("Caption" and "caption"), and both rows stay editable.
 *
 * The check reads, then the caller writes: run both inside serialized() so two requests cannot both pass it.
 */
class UniquePropertyName implements ValidationRule
{
    public function __construct(
        private readonly int $assetId,
        private readonly ?string $language = null,
        private readonly ?int $ignoreId = null,
        private readonly ?string $currentName = null,
    ) {}

    /**
     * Runs $write (validation plus the insert or update) in a transaction holding the owning asset's row lock, the
     * one its other writers take, so a concurrent create of a case variant waits and then fails validation.
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    public static function serialized(int $assetId, \Closure $write): mixed
    {
        return DB::transaction(function () use ($assetId, $write) {
            Asset::query()->whereKey($assetId)->lockedForGraph()->value('id');

            return $write();
        });
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $name = mb_strtolower(trim($value));

        if ($this->currentName !== null && $name === mb_strtolower(trim($this->currentName))) {
            return;
        }

        $taken = AssetProperty::query()
            ->where('dam_asset_id', $this->assetId)
            ->whereNull('field_id')
            ->whereRaw('LOWER(name) = ?', [$name])
            ->when($this->language !== null, fn ($query) => $query->where('language', $this->language))
            ->when($this->ignoreId !== null, fn ($query) => $query->whereKeyNot($this->ignoreId))
            ->exists();

        if ($taken) {
            $fail(trans('dam::app.admin.validation.property.name.unique'));
        }
    }
}
