<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Core\Filesystem\FileStorer;
use Mothership\Core\Repositories\LocaleRepository;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Repositories\AssetPropertyRepository;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Rules\UniquePropertyName;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\AssetAccessControl;

class PropertyController extends Controller
{
    use AssetAccessControl;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetPropertyRepository $assetPropertyRepository,
        protected FileStorer $fileStorer,
        protected LocaleRepository $localeRepository
    ) {}

    public function properties(int $id)
    {
        $property = $this->assetPropertyRepository->find($id);

        // A row on an asset the caller cannot open answers like a missing row (hidden equals missing).
        if (! $property || ! $this->damCanAccessAsset((int) $property->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.properties.index.not-found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.properties.index.found-success'),
            'data'    => $property,
        ], 200);
    }

    public function addProperty(int $id)
    {
        $this->damVisibleAssetOrFail($id);

        if (($asset = Asset::query()->find($id)) !== null && ($refusal = app(RoleBuilder::class)->refusal($asset, 'own-only-details'))) {
            return response()->json(['success' => false, 'message' => $refusal], 403);
        }

        $messages = [
            'name.required' => trans('dam::app.admin.validation.property.name.required'),
            'name.unique'   => trans('dam::app.admin.validation.property.name.unique'),
        ];

        // Validate and write under the asset's row lock, so a concurrent case variant cannot slip past the check.
        return UniquePropertyName::serialized($id, function () use ($id, $messages) {
            $this->validate(request(), [
                'type'     => 'required',
                'language' => 'required',
                'value'    => 'required|max:1000',
                'name'     => [
                    'required',
                    'min:3',
                    'max:100',
                    // P4a: a field's value row is not a free-text property, so it does not take the name.
                    new UniquePropertyName(
                        assetId: $id,
                        language: (string) ($this->localeRepository->where('code', request()->get('language'))->where('status', 1)->first()?->id ?? 0),
                    ),
                ],
            ], $messages);

            $language = request()->get('language');
            $locale = $this->localeRepository->where('code', $language)->where('status', 1)->first();

            if (! $locale) {
                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.validation.property.language.not-found'),
                ], 400);
            }

            // P4a: a property named after a field is that field's value, typed and checked like any other write.
            if (($typed = app(FieldValues::class)->writeThroughProperty(asset: Asset::query()->findOrFail($id), row: null, name: (string) request('name'), raw: request('value'), source: 'api-property')) !== false) {
                return response()->json([
                    'success'  => true,
                    'message'  => trans('dam::app.admin.dam.asset.properties.index.create-success'),
                    'property' => $typed,
                ], 200);
            }

            $property = $this->assetPropertyRepository->create([
                'name'         => request('name'),
                'type'         => request('type'),
                'language'     => $locale->id,
                'value'        => request('value'),
                'dam_asset_id' => $id,
            ]);

            return response()->json([
                'success'  => true,
                'message'  => trans('dam::app.admin.dam.asset.properties.index.create-success'),
                'property' => $property,
            ], 200);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $property = $this->assetPropertyRepository->find($id);

        if (! $property || ! $this->damCanAccessAsset((int) $property->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.properties.index.not-found'),
            ], 404);
        }

        if (($asset = Asset::query()->find($property->dam_asset_id)) !== null && ($refusal = app(RoleBuilder::class)->refusal($asset, 'own-only-details'))) {
            return response()->json(['success' => false, 'message' => $refusal], 403);
        }

        // Validate and write under the asset's row lock, so a concurrent case variant cannot slip past the check.
        return UniquePropertyName::serialized((int) $property->dam_asset_id, function () use ($request, $id, $property) {
            // The row as it is now, under the lock: its name decides what counts as unchanged.
            $property->refresh();

            $this->validate($request, [
                'name'  => ['required', 'min:3', 'max:100', new UniquePropertyName(assetId: (int) $property->dam_asset_id, ignoreId: $id, currentName: (string) $property->name)],
                // The same limit as create (UX eval G2-04).
                'value' => 'required|max:1000',
            ]);

            if (($typed = app(FieldValues::class)->writeThroughProperty(asset: Asset::query()->findOrFail($property->dam_asset_id), row: $property, name: (string) $request->input('name'), raw: $request->input('value'), source: 'api-property')) !== false) {
                return response()->json([
                    'success'  => true,
                    'message'  => trans('dam::app.admin.dam.asset.properties.index.update-success'),
                    'property' => $typed,
                ], 200);
            }

            try {
                $updatedProperty = $this->assetPropertyRepository->update($request->only(['name', 'value']), $id);

                return response()->json([
                    'success'  => true,
                    'message'  => trans('dam::app.admin.dam.asset.properties.index.update-success'),
                    'property' => $updatedProperty,
                ], 200);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.dam.asset.properties.index.update-failure'),
                    'error'   => $e->getMessage(),
                ], 500);
            }
        });
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        $property = $this->assetPropertyRepository->find($id);

        if (! $property || ! $this->damCanAccessAsset((int) $property->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.properties.index.not-found'),
            ], 404);
        }

        $asset = Asset::query()->find($property->dam_asset_id);

        if ($asset !== null && ($refusal = app(RoleBuilder::class)->refusal($asset, 'own-only-delete'))) {
            return response()->json(['success' => false, 'message' => $refusal], 403);
        }

        try {
            // P5b: deleting a field's value row is a person clearing that field (the delete and the removal are one step).
            app(FieldValues::class)->deleteByPerson(row: $property, actorId: auth()->guard('api')->id(), delete: fn () => $this->assetPropertyRepository->delete($id));

            return response()->json([
                'success' => true,
                'message' => trans('dam::app.admin.dam.asset.properties.index.delete-success'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.properties.index.delete-failed'),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
