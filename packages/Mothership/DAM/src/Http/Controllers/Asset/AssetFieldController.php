<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\MetadataFields\FieldValueConflict;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\MetadataFields\FieldValueValidator;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\MetadataFieldLog;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * Typed field values from the Library (P4a, D9): the modal's Details editor and the bulk "Edit fields" action. Both
 * validate through FieldValueValidator, as REST does, and need dam.asset.update (an editor).
 */
class AssetFieldController extends Controller
{
    use AssetAccessControl;

    /** The most assets one bulk edit touches. */
    public const MAX_BULK = 500;

    public function __construct(
        protected FieldValues $values,
        protected FieldValueValidator $validator,
    ) {}

    public function update(Request $request, int $id): JsonResponse
    {
        // A hidden asset answers like a missing one before the permission check can tell them apart.
        $asset = $this->damVisibleAssetOrFail($id);
        abort_unless(bouncer()->hasPermission('dam.asset.update'), 403, trans('dam::app.admin.permissions.unauthorized'));
        // A role limited to its own items edits the details of files it added only (the writer asks again under its lock).
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset);

        // The editor sends only the fields the person changed, with `expected`: the values it loaded. A field someone
        // else saved meanwhile refuses the whole save (409), so a stale editor never writes over their value.
        $request->validate(['fields' => 'present|array', 'expected' => 'sometimes|array']);
        $normalized = $this->validator->validate(values: $request->input('fields', []));

        try {
            $this->values->write(asset: $asset, values: $normalized, source: 'web', expected: $request->has('expected') ? (array) $request->input('expected') : null);
        } catch (FieldValueConflict $conflict) {
            return response()->json([
                'message'   => trans('dam::app.admin.metadata-fields.editor.conflict'),
                'conflicts' => $conflict->codes,
            ], 409);
        }

        return response()->json($this->values->forAsset($asset->id) + [
            // The Library card refreshes its field lines from this without reloading the listing.
            'card_fields' => $this->values->cardFields([(int) $asset->id])[(int) $asset->id] ?? [],
            'message'     => trans('dam::app.admin.metadata-fields.values-saved'),
        ]);
    }

    /**
     * Sets the same values on every selected asset the editor may open; the values are checked once, before any write,
     * so one bad value changes nothing. Hidden ids are skipped like missing ones.
     */
    public function massUpdate(Request $request): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.update'), 403, trans('dam::app.admin.permissions.unauthorized'));

        $request->validate([
            'indices'   => 'required|array|min:1|max:'.self::MAX_BULK,
            'indices.*' => 'integer|min:1',
            'fields'    => 'required|array|min:1',
        ]);
        $normalized = $this->validator->validate(values: $request->input('fields'));
        $updated = 0;
        $skipped = 0;
        $roles = app(RoleBuilder::class);
        $editable = array_flip($roles->ownedAssetIds($request->input('indices')));

        foreach (array_unique(array_map('intval', $request->input('indices'))) as $assetId) {
            $asset = Asset::query()->find($assetId);

            if (! $asset || ! $this->damCanAccessAsset($assetId)) {
                continue;
            }

            // A role limited to its own items sets values only on files it added; the rest are skipped and counted.
            if (! isset($editable[$assetId])) {
                $skipped++;

                continue;
            }

            $this->values->write(asset: $asset, values: $normalized, source: 'bulk');
            $updated++;
        }

        if ($skipped > 0 && $updated === 0) {
            return response()->json(['count' => 0, 'skipped' => $skipped, 'message' => trans('dam::app.admin.permissions.own-only-mass-details-none', ['skipped' => $skipped])], 403);
        }

        MetadataFieldLog::write('field_values.bulk', ['requested' => count($request->input('indices')), 'updated' => $updated, 'codes' => array_keys($normalized)]);

        return response()->json(array_filter([
            'count'   => $updated,
            'skipped' => $skipped ?: null,
            'message' => $skipped > 0
                ? trans('dam::app.admin.permissions.own-only-mass-details', ['updated' => $updated, 'skipped' => $skipped])
                : trans_choice('dam::app.admin.metadata-fields.bulk.success', $updated, ['count' => $updated]),
        ], fn ($value) => $value !== null));
    }
}
